<?php

namespace App\Domain\Progress\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Progress\Models\PageProgress;
use App\Domain\Progress\Repositories\PageProgressRepository;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Services\SystemLogService;
use Illuminate\Support\Collection;

/**
 * Progress for page-based (scanned) books.
 *
 * A page is finished when the pupil has marked it read and — if the page has
 * words on it — read it aloud well enough. Pages with no text (picture-only or
 * blank OCR) finish on the read mark alone, so a book can never dead-end.
 */
class PageProgressService
{
    public function __construct(
        private PageProgressRepository $repository,
        private SystemLogService $logs,
    ) {}

    /** The pupil marked this page as read. */
    public function markRead(Student $student, BookPage $page): PageProgress
    {
        $progress = $this->repository->firstOrCreateFor($student->id, $page->id);
        $progress->is_read = true;

        return $this->reconcile($student, $page, $progress);
    }

    /** A scored read-aloud came back for this page. */
    public function recordPronunciation(Student $student, BookPage $page, ?float $score): PageProgress
    {
        $progress = $this->repository->firstOrCreateFor($student->id, $page->id);

        if ($score !== null) {
            $progress->best_score = max((int) $progress->best_score, (int) round($score));

            if ($score >= ProgressService::PRONUNCIATION_PASS) {
                $progress->pronunciation_passed = true;
                // Reading it aloud is reading it — no need to also tap the button.
                $progress->is_read = true;
            }
        }

        return $this->reconcile($student, $page, $progress);
    }

    /**
     * The pages of a book annotated with this pupil's progress.
     *
     * @return array<string, mixed>
     */
    public function forBook(Student $student, Book $book): array
    {
        $progressMap = $this->repository->forStudent($student->id);
        $pages = $book->pages()->orderBy('page_number')->get();

        $items = $pages->map(function (BookPage $page) use ($progressMap) {
            $progress = $progressMap->get($page->id);

            return [
                'id' => $page->id,
                'page_number' => $page->page_number,
                'has_text' => filled($page->text),
                'is_read' => (bool) optional($progress)->is_read,
                'pronunciation_passed' => (bool) optional($progress)->pronunciation_passed,
                'best_score' => optional($progress)->best_score,
                'is_completed' => optional($progress)->completed_at !== null,
            ];
        })->values()->all();

        $total = count($items);
        $completed = collect($items)->where('is_completed', true)->count();

        return [
            'pages' => $items,
            'total_pages' => $total,
            'completed_pages' => $completed,
            'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'is_completed' => $total > 0 && $completed === $total,
        ];
    }

    /**
     * Completion summary for a page-based book, without the per-page detail.
     *
     * @param  Collection<int, PageProgress>|null  $progressMap  reuse when looping over books
     * @return array{total: int, completed: int, percent: int, is_completed: bool}
     */
    public function summaryForBook(Student $student, Book $book, ?Collection $progressMap = null): array
    {
        $progressMap ??= $this->repository->forStudent($student->id);
        $pages = $book->relationLoaded('pages') ? $book->pages : $book->pages()->get();

        $total = $pages->count();
        $completed = $pages->filter(
            fn (BookPage $page) => optional($progressMap->get($page->id))->completed_at !== null
        )->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'is_completed' => $total > 0 && $completed === $total,
        ];
    }

    /**
     * Every page-progress row for a pupil, keyed by page id — pass it to
     * summaryForBook when looping over books to avoid a query per book.
     *
     * @return Collection<int, PageProgress>
     */
    public function pageProgressFor(Student $student): Collection
    {
        return $this->repository->forStudent($student->id);
    }

    /** How many pages this pupil has finished, across every book. */
    public function completedPageCount(Student $student): int
    {
        return $this->repository->forStudent($student->id)
            ->filter(fn (PageProgress $progress) => $progress->completed_at !== null)
            ->count();
    }

    /** Mark the row complete once its requirements are met, then save. */
    private function reconcile(Student $student, BookPage $page, PageProgress $progress): PageProgress
    {
        $needsReadAloud = filled($page->text);
        $done = $progress->is_read && (! $needsReadAloud || $progress->pronunciation_passed);

        if ($done && $progress->completed_at === null) {
            $progress->completed_at = now();

            $this->logs->record(
                'page.completed',
                "{$student->full_name} finished page {$page->page_number} of \"{$page->book?->title}\".",
                $student,
            );
        }

        return $this->repository->save($progress);
    }
}
