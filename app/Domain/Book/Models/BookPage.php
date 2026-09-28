<?php

namespace App\Domain\Book\Models;

use App\Domain\Chapter\Models\Chapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BookPage extends Model
{
    protected $table = 'book_pages';

    protected $fillable = [
        'book_id',
        'chapter_id',
        'page_number',
        'image_path',
        'text',
    ];

    protected $casts = [
        'chapter_id' => 'integer',
        'page_number' => 'integer',
    ];

    protected $appends = ['image_url'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** The chapter this page belongs to (every book is Book → Chapters → Pages). */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }
}
