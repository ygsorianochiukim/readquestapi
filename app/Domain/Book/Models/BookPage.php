<?php

namespace App\Domain\Book\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BookPage extends Model
{
    protected $table = 'book_pages';

    protected $fillable = [
        'book_id',
        'page_number',
        'image_path',
        'text',
    ];

    protected $casts = [
        'page_number' => 'integer',
    ];

    protected $appends = ['image_url'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }
}
