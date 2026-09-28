<?php

namespace App\Domain\Progress\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Support\Facades\DB;

/**
 * How far a teacher's class has got through the material they were given.
 *
 * The per-pupil view already exists on the progress screen. This is the other
 * question a teacher asks — "is this book working, and is anyone actually
 * finishing it?" — answered on the screens where the books and chapters
 * themselves are managed.
 *
 * Every method answers for a whole list in one query rather than one per row:
 * a class of forty against a shelf of twenty books is otherwise eight hundred
 * round trips to render one page.
 */
class ClassProgressService
{
    /**
     * Completion of every book, keyed by book id.
     *
     * A book nobody has been assigned reports zero assigned rather than being
     * absent, so the UI can say "not assigned yet" instead of showing nothing.
     *
     * @return array<int, array{assigned: int, completed: int, percent: int}>
     */
    public function bookSummaries(Teachers $teacher): array
    {
        $studentIds = $teacher->students()->pluck('students.id');

        if ($studentIds->isEmpty()) {
            return [];
        }

        // How many of this teacher's pupils each book is assigned to.
        $assigned = DB::table('book_assignments')
            ->whereIn('student_id', $studentIds)
            ->groupBy('book_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'book_id');

        $chapterCompletions = $this->chapterCompletionsPerBook($studentIds);
        // A picture book's chapters only group its pages, so it is still
        // measured in pages: leave its chapters out of the chapter count.
        $chapterTotals = DB::table('chapters')
            ->join('books', 'books.id', '=', 'chapters.book_id')
            ->where('books.type', '!=', 'scanned')
            ->groupBy('chapters.book_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'book_id');

        $pageCompletions = $this->pageCompletionsPerBook($studentIds);
        $pageTotals = DB::table('book_pages')
            ->groupBy('book_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'book_id');

        $summaries = [];

        foreach ($assigned as $bookId => $assignedCount) {
            // A chapter book is measured in chapters, a scanned one in pages.
            $unitsPerPupil = (int) ($chapterTotals[$bookId] ?? 0);
            $done = $chapterCompletions[$bookId] ?? [];

            if ($unitsPerPupil === 0) {
                $unitsPerPupil = (int) ($pageTotals[$bookId] ?? 0);
                $done = $pageCompletions[$bookId] ?? [];
            }

            $summaries[(int) $bookId] = [
                'assigned' => (int) $assignedCount,
                // A pupil has finished the book when they have finished every
                // unit in it.
                'completed' => $unitsPerPupil === 0
                    ? 0
                    : count(array_filter($done, fn (int $count) => $count >= $unitsPerPupil)),
                'percent' => $this->averagePercent($done, $assignedCount, $unitsPerPupil),
            ];
        }

        return $summaries;
    }

    /**
     * How many of a teacher's pupils have completed each chapter of a book.
     *
     * @return array<int, array{assigned: int, completed: int, percent: int}>
     */
    public function chapterSummaries(Teachers $teacher, Book $book): array
    {
        $studentIds = $teacher->students()->pluck('students.id');
        $chapterIds = $book->chapters()->pluck('id');

        if ($studentIds->isEmpty() || $chapterIds->isEmpty()) {
            return [];
        }

        $assigned = DB::table('book_assignments')
            ->whereIn('student_id', $studentIds)
            ->where('book_id', $book->id)
            ->count();

        if ($book->isPageBased()) {
            return $this->pageChapterSummaries($studentIds, $chapterIds, $assigned);
        }

        $completed = DB::table('reading_progress')
            ->whereIn('student_id', $studentIds)
            ->whereIn('chapter_id', $chapterIds)
            ->where('status', 'completed')
            ->groupBy('chapter_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'chapter_id');

        $summaries = [];

        foreach ($chapterIds as $chapterId) {
            $done = (int) ($completed[$chapterId] ?? 0);

            $summaries[(int) $chapterId] = [
                'assigned' => $assigned,
                'completed' => $done,
                'percent' => $assigned > 0 ? (int) round($done / $assigned * 100) : 0,
            ];
        }

        return $summaries;
    }

    /**
     * A picture book's chapters are finished page by page: a pupil has
     * finished a chapter once every page in it is finished.
     *
     * @return array<int, array{assigned: int, completed: int, percent: int}>
     */
    private function pageChapterSummaries($studentIds, $chapterIds, int $assigned): array
    {
        $pageTotals = DB::table('book_pages')
            ->whereIn('chapter_id', $chapterIds)
            ->groupBy('chapter_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'chapter_id');

        $rows = DB::table('page_progress')
            ->join('book_pages', 'book_pages.id', '=', 'page_progress.book_page_id')
            ->whereIn('page_progress.student_id', $studentIds)
            ->whereIn('book_pages.chapter_id', $chapterIds)
            ->whereNotNull('page_progress.completed_at')
            ->groupBy('book_pages.chapter_id', 'page_progress.student_id')
            ->get([
                'book_pages.chapter_id',
                DB::raw('COUNT(*) as total'),
            ]);

        $summaries = [];

        foreach ($chapterIds as $chapterId) {
            $pages = (int) ($pageTotals[$chapterId] ?? 0);
            $done = $pages === 0 ? 0 : $rows
                ->where('chapter_id', $chapterId)
                ->filter(fn ($row) => (int) $row->total >= $pages)
                ->count();

            $summaries[(int) $chapterId] = [
                'assigned' => $assigned,
                'completed' => $done,
                'percent' => $assigned > 0 ? (int) round($done / $assigned * 100) : 0,
            ];
        }

        return $summaries;
    }

    /**
     * Completed chapters per pupil, per book.
     *
     * @return array<int, array<int, int>>  book id => (student id => chapters done)
     */
    private function chapterCompletionsPerBook($studentIds): array
    {
        $rows = DB::table('reading_progress')
            ->join('chapters', 'chapters.id', '=', 'reading_progress.chapter_id')
            ->whereIn('reading_progress.student_id', $studentIds)
            ->where('reading_progress.status', 'completed')
            ->groupBy('chapters.book_id', 'reading_progress.student_id')
            ->get([
                'chapters.book_id',
                'reading_progress.student_id',
                DB::raw('COUNT(*) as total'),
            ]);

        return $this->groupByBook($rows);
    }

    /**
     * Completed pages per pupil, per book.
     *
     * @return array<int, array<int, int>>
     */
    private function pageCompletionsPerBook($studentIds): array
    {
        $rows = DB::table('page_progress')
            ->join('book_pages', 'book_pages.id', '=', 'page_progress.book_page_id')
            ->whereIn('page_progress.student_id', $studentIds)
            ->whereNotNull('page_progress.completed_at')
            ->groupBy('book_pages.book_id', 'page_progress.student_id')
            ->get([
                'book_pages.book_id',
                'page_progress.student_id',
                DB::raw('COUNT(*) as total'),
            ]);

        return $this->groupByBook($rows);
    }

    /** @return array<int, array<int, int>> */
    private function groupByBook($rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->book_id][(int) $row->student_id] = (int) $row->total;
        }

        return $out;
    }

    /**
     * Average progress across everyone the book was assigned to.
     *
     * Pupils who have not started count as zero — leaving them out would make a
     * book nobody has opened look finished.
     *
     * @param  array<int, int>  $done
     */
    private function averagePercent(array $done, int $assignedCount, int $unitsPerPupil): int
    {
        if ($assignedCount === 0 || $unitsPerPupil === 0) {
            return 0;
        }

        $total = 0;

        foreach ($done as $count) {
            $total += min(100, $count / $unitsPerPupil * 100);
        }

        return (int) round($total / $assignedCount);
    }
}
