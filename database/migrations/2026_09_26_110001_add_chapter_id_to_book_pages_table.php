<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every book is Book → Chapters → Content now, picture books included: their
 * pages hang off a chapter instead of straight off the book.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_pages', function (Blueprint $table) {
            // Nullable so deleting a chapter never takes a page with it by
            // accident; the chapter service moves or removes pages explicitly.
            $table->foreignId('chapter_id')->nullable()->after('book_id')
                ->constrained('chapters')->nullOnDelete();
        });

        // Existing picture books were flat. Give each one a single chapter
        // holding all its pages, so nothing is left outside a chapter.
        $bookIds = DB::table('book_pages')
            ->join('books', 'books.id', '=', 'book_pages.book_id')
            ->where('books.type', 'scanned')
            ->whereNull('book_pages.chapter_id')
            ->distinct()
            ->pluck('book_pages.book_id');

        foreach ($bookIds as $bookId) {
            $chapterId = DB::table('chapters')
                ->where('book_id', $bookId)
                ->orderBy('chapter_number')
                ->value('id');

            if (! $chapterId) {
                $chapterId = DB::table('chapters')->insertGetId([
                    'book_id' => $bookId,
                    'chapter_number' => 1,
                    'title' => 'Chapter 1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('book_pages')
                ->where('book_id', $bookId)
                ->whereNull('chapter_id')
                ->update(['chapter_id' => $chapterId]);
        }
    }

    public function down(): void
    {
        Schema::table('book_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chapter_id');
        });
    }
};
