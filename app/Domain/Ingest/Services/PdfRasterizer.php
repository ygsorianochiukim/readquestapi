<?php

namespace App\Domain\Ingest\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Turns a PDF into one PNG per page.
 *
 * Children read from the page they can see, so a scanned book has to keep its
 * pictures — text alone would strip the illustrations out of a picture book.
 * There is no single tool for this that is present everywhere, so we try what
 * is installed, in order of how good the result is, and tell the caller
 * honestly when none of it is available rather than failing the upload.
 */
class PdfRasterizer
{
    /** Enough for clean OCR of children's type without producing huge files. */
    private const DPI = 200;

    /** A guard against someone uploading an entire library as one file. */
    public const MAX_PAGES = 200;

    /**
     * Whether this machine can produce page images at all.
     */
    public function isAvailable(): bool
    {
        return $this->tool() !== null;
    }

    /**
     * Which converter we will use, or null when the host has none of them.
     * Imagick first because it needs no shelling out; then the two command-line
     * tools, either of which usually comes with a PDF-capable host.
     */
    public function tool(): ?string
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            return 'imagick';
        }

        foreach (['pdftoppm', 'gs'] as $binary) {
            if ($this->binaryExists($binary)) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * Render each page of a PDF to a PNG inside $outputDir.
     *
     * @return list<string> absolute paths, in page order
     *
     * @throws RuntimeException when no converter is available or the run fails.
     */
    public function rasterize(string $pdfPath, string $outputDir): array
    {
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('Could not create a working folder for the upload.');
        }

        return match ($this->tool()) {
            'imagick' => $this->withImagick($pdfPath, $outputDir),
            'pdftoppm' => $this->withPdftoppm($pdfPath, $outputDir),
            'gs' => $this->withGhostscript($pdfPath, $outputDir),
            default => throw new RuntimeException(
                'This server cannot turn PDFs into page images. Install the PHP imagick extension, poppler-utils or Ghostscript — or upload the pages as images.'
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function withImagick(string $pdfPath, string $outputDir): array
    {
        $imagick = new \Imagick;
        $imagick->setResolution(self::DPI, self::DPI);
        $imagick->readImage($pdfPath);
        $imagick = $imagick->coalesceImages();

        $paths = [];
        $page = 0;

        foreach ($imagick as $frame) {
            if ($page >= self::MAX_PAGES) {
                break;
            }

            $frame->setImageFormat('png');
            // A scan on a phone camera is often a JPEG inside the PDF with an
            // alpha channel Imagick keeps; flattening avoids black pages.
            $frame->setImageBackgroundColor('white');
            $frame = $frame->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

            $path = sprintf('%s/page-%04d.png', $outputDir, $page + 1);
            $frame->writeImage($path);
            $paths[] = $path;
            $page++;
        }

        $imagick->clear();

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function withPdftoppm(string $pdfPath, string $outputDir): array
    {
        $result = Process::timeout(300)->run([
            'pdftoppm',
            '-png',
            '-r', (string) self::DPI,
            '-l', (string) self::MAX_PAGES,
            $pdfPath,
            $outputDir.'/page',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Could not split the PDF into pages: '.trim($result->errorOutput()));
        }

        return $this->collect($outputDir);
    }

    /**
     * @return list<string>
     */
    private function withGhostscript(string $pdfPath, string $outputDir): array
    {
        $result = Process::timeout(300)->run([
            'gs',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=png16m',
            '-r'.self::DPI,
            '-dLastPage='.self::MAX_PAGES,
            '-sOutputFile='.$outputDir.'/page-%04d.png',
            $pdfPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Could not split the PDF into pages: '.trim($result->errorOutput()));
        }

        return $this->collect($outputDir);
    }

    /**
     * Page files in page order. The tools pad their numbering differently, so
     * sort naturally rather than trusting glob's byte order.
     *
     * @return list<string>
     */
    private function collect(string $outputDir): array
    {
        $files = glob($outputDir.'/page*.png') ?: [];
        natsort($files);

        return array_values(array_slice($files, 0, self::MAX_PAGES));
    }

    private function binaryExists(string $binary): bool
    {
        $probe = stripos(PHP_OS_FAMILY, 'windows') === 0
            ? ['where', $binary]
            : ['which', $binary];

        try {
            return Process::timeout(10)->run($probe)->successful();
        } catch (\Throwable) {
            // A host that forbids running processes at all is a host without
            // these tools, as far as this is concerned.
            return false;
        }
    }
}
