<?php

namespace App\Domain\Progress\Models;

use App\Domain\Book\Models\BookPage;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pupil's progress on one page of a page-based (scanned) book.
 */
class PageProgress extends Model
{
    protected $table = 'page_progress';

    protected $fillable = [
        'student_id',
        'book_page_id',
        'is_read',
        'pronunciation_passed',
        'best_score',
        'completed_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'pronunciation_passed' => 'boolean',
        'best_score' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BookPage::class, 'book_page_id');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
