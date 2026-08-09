<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ocr\Services\OcrService;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Reads printed text out of a photo or scan so a teacher can type less.
 * Used when filling in a chapter's story text from the printed DepEd book.
 */
class OcrController extends Controller
{
    public function __construct(
        private OcrService $ocr,
        private SystemLogService $logs,
    ) {}

    public function extract(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'max:8192'], // up to 8 MB
        ]);

        if (! $this->ocr->isConfigured()) {
            return response()->json([
                'message' => 'Scanning is not set up yet. Add AZURE_VISION_KEY and AZURE_VISION_ENDPOINT to enable it.',
            ], 503);
        }

        try {
            $text = $this->ocr->extractText(
                file_get_contents($request->file('file')->getRealPath())
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $this->explain($exception->getMessage()),
            ], 502);
        }

        $teacher = $request->user();
        $this->logs->record(
            'ocr.extracted',
            sprintf(
                '%s scanned a page and pulled out %d characters of text.',
                $teacher->full_name,
                mb_strlen($text),
            ),
            null,
            $teacher,
        );

        return response()->json([
            'data' => [
                'text' => $text,
                'characters' => mb_strlen($text),
                'lines' => $text === '' ? 0 : count(explode("\n", $text)),
            ],
        ]);
    }

    /**
     * Translate Azure's error code into something a teacher can act on.
     * Anything unrecognised keeps Azure's own wording rather than hiding it.
     */
    private function explain(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'InvalidImageSize') => 'That image file is too big. Azure accepts images up to 4 MB — take the photo at a lower resolution, or crop it to just the page.',
            str_contains($reason, 'InvalidImageDimension') => 'That image is the wrong size in pixels. It must be at least 50×50 and no more than 10000×10000.',
            str_contains($reason, 'InvalidImageFormat'),
            str_contains($reason, 'NotSupportedImage') => 'That file type cannot be scanned. Save the page as a JPG or PNG and try again.',
            str_contains($reason, 'TooManyRequests') => 'Too many scans at once. Wait about a minute, then scan the next page.',
            str_contains($reason, 'Unauthorized'),
            str_contains($reason, 'InvalidSubscriptionKey'),
            str_contains($reason, '401'),
            str_contains($reason, '403') => 'Azure rejected the Vision key. Check AZURE_VISION_KEY and AZURE_VISION_ENDPOINT.',
            str_contains($reason, 'timed out') => 'The scan took too long. Try again with a smaller or clearer image.',
            default => 'Could not read that image ('.$reason.'). Try a clearer, straighter photo of the page.',
        };
    }
}
