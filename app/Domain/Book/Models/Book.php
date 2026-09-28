<?php

namespace App\Domain\Book\Models;

use App\Domain\Chapter\Models\Chapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    protected $table = 'books';

    protected $fillable = [
        'title',
        'type',
        'description',
        'cover_image_url',
        'reading_level',
        'sequence',
        'status',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

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
