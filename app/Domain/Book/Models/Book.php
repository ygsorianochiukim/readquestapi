<?php

namespace App\Domain\Book\Models;

use App\Domain\Chapter\Models\Chapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    protected $table = 'books';

    protected $fillable = [
        'teacher_id',
        'title',
        'type',
        'theme',
        'description',
        'cover_image_url',
        'reading_level',
        'sequence',
        'status',
    ];

    protected $casts = [
        'teacher_id' => 'integer',
        'sequence' => 'integer',
    ];

    /**
     * Published books a teacher can hand out: their own uploads, plus the
     * shared ones from before uploads were tracked to a teacher.
     */
    public function scopeAssignableBy(Builder $query, int $teacherId): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $owned) => $owned->where('teacher_id', $teacherId)->orWhereNull('teacher_id'));
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('chapter_number');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class)->orderBy('page_number');
    }

    /**
     * A picture book: pupils read it page by page, its pages grouped into
     * chapters. A standard book's chapters carry story text and run the full
     * learning loop (read, read aloud, game, quiz) instead.
     */
    public function isPageBased(): bool
    {
        return $this->type === 'scanned';
    }
}
