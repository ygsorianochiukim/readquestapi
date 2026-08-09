<?php

namespace App\Domain\Progress\Repositories;

use App\Domain\Progress\Models\PageProgress;
use Illuminate\Database\Eloquent\Collection;

class PageProgressRepository
{
    /** Get (or create) the progress row for a student + page. */
    public function firstOrCreateFor(int $studentId, int $pageId): PageProgress
    {
        return PageProgress::firstOrCreate(
            ['student_id' => $studentId, 'book_page_id' => $pageId],
        );
    }

    /**
     * All page-progress rows for a student, keyed by book_page_id.
     *
     * @return Collection<int, PageProgress>
     */
    public function forStudent(int $studentId): Collection
    {
        return PageProgress::where('student_id', $studentId)
            ->get()
            ->keyBy('book_page_id');
    }

    public function save(PageProgress $progress): PageProgress
    {
        $progress->save();

        return $progress;
    }
}
