<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who uploaded each book, so a teacher assigning books picks from their own
 * uploads. Books from before this (no upload on record) stay unowned and are
 * shared with every teacher, so nothing already in use disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('id')->constrained('teachers')->nullOnDelete();
        });

        // The teacher of a book's first upload is the one who brought it in.
        $owners = DB::table('ingest_batches')
            ->select('book_id', DB::raw('MIN(id) as first_batch'))
            ->whereNotNull('book_id')
            ->groupBy('book_id')
            ->pluck('first_batch', 'book_id');

        foreach ($owners as $bookId => $batchId) {
            $teacherId = DB::table('ingest_batches')->where('id', $batchId)->value('teacher_id');
            DB::table('books')->where('id', $bookId)->update(['teacher_id' => $teacherId]);
        }
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
        });
    }
};
