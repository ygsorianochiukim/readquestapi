<?php

namespace App\Domain\Progress\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Celebration\CelebrationBag;
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
        private CelebrationBag $celebrations,
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
     * With $chapterId only that chapter's pages are listed (and `chapter`
     * summarises it); the totals always describe the whole book, so
     * `is_completed` keeps meaning "the book is finished".
     *
     * @return array<string, mixed>
     */
    public function forBook(Student $student, Book $book, ?int $chapterId = null): array
    {
        $progressMap = $this->repository->forStudent($student->id);
        $pages = $book->pages()->orderBy('page_number')->get();

        $items = $pages->map(function (BookPage $page) use ($progressMap) {
            $progress = $progressMap->get($page->id);

            return [
                'id' => $page->id,
                'chapter_id' => $page->chapter_id,
                'page_number' => $page->page_number,
                'has_text' => filled($page->text),
                'is_read' => (bool) optional($progress)->is_read,
                'pronunciation_passed' => (bool) optional($progress)->pronunciation_passed,
                'best_score' => optional($progress)->best_score,
                'is_completed' => optional($progress)->completed_at !== null,
            ];
        })->values();

        $total = $items->count();
        $completed = $items->where('is_completed', true)->count();
        $chapters = $this->chaptersForBook($student, $book, $progressMap);

        $result = [
            'pages' => $chapterId === null
                ? $items->all()
                : $items->where('chapter_id', $chapterId)->values()->all(),
            'total_pages' => $total,
            'completed_pages' => $completed,
            'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'is_completed' => $total > 0 && $completed === $total,
            'page_chapters' => $chapters,
        ];

        if ($chapterId !== null) {
            $chapter = collect($chapters)->firstWhere('id', $chapterId);
            $result['chapter'] = $chapter ? $chapter + [
                'is_completed' => $chapter['page_count'] > 0 && $chapter['pages_completed'] === $chapter['page_count'],
            ] : null;
        }

        return $result;
    }

    /**
     * A picture book's chapters with this pupil's progress through each. A
     * chapter is finished when every page in it is.
     *
     * @param  Collection<int, PageProgress>|null  $progressMap  reuse when looping over books
     * @return list<array{id: int, title: string, sequence: int, page_count: int, pages_completed: int, first_page_id: ?int}>
     */
    public function chaptersForBook(Student $student, Book $book, ?Collection $progressMap = null): array
    {
        $progressMap ??= $this->repository->forStudent($student->id);
        $chapters = $book->relationLoaded('chapters') ? $book->chapters : $book->chapters()->get();
        $pages = ($book->relationLoaded('pages') ? $book->pages : $book->pages()->get())
            ->sortBy('page_number')
            ->groupBy('chapter_id');

        return $chapters->sortBy('chapter_number')->map(function ($chapter) use ($pages, $progressMap) {
            $inChapter = $pages->get($chapter->id, collect());

            return [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'theme' => $chapter->theme,
                'sequence' => $chapter->chapter_number,
                'page_count' => $inChapter->count(),
                'pages_completed' => $inChapter->filter(
                    fn (BookPage $page) => optional($progressMap->get($page->id))->completed_at !== null
                )->count(),
                'first_page_id' => $inChapter->first()?->id,
            ];
        })->values()->all();
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

            $this->celebrations->milestone('page_completed');

            $this->logs->record(
                'page.completed',
                "{$student->full_name} finished page {$page->page_number} of \"{$page->book?->title}\".",
                $student,
            );
        }

        return $this->repository->save($progress);
    }
}
