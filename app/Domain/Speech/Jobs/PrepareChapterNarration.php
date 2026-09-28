<?php

namespace App\Domain\Speech\Jobs;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Services\ChapterParagraphs;
use App\Domain\Speech\Services\TextToSpeechService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Speak every page of a chapter ahead of time, so a child who presses
 * "Listen" hears it at once instead of waiting for Azure.
 *
 * Runs in the background after a book is published. Each paragraph is its own
 * short request, and one that fails is simply left to be made on first listen.
 */
class PrepareChapterNarration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $chapterId) {}

    public function handle(TextToSpeechService $tts, ChapterParagraphs $paragraphs): void
    {
        $chapter = Chapter::find($this->chapterId);

        if (! $chapter || ! $tts->isConfigured()) {
            return;
        }

        foreach ($paragraphs->forChapter($chapter) as $paragraph) {
            try {
                $tts->cachedAudio(
                    TextToSpeechService::pageName($paragraph['book_page_id'], $paragraph['paragraph_index']),
                    $paragraph['text'],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
