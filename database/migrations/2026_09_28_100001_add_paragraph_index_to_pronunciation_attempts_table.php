<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chapter is read aloud one paragraph at a time. The attempt keeps which
     * paragraph of its page it was, so the chapter can tell when every one of
     * them has been read.
     */
    public function up(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('paragraph_index')->nullable()->after('chapter_id');
            $table->index(['student_id', 'chapter_id', 'book_page_id', 'paragraph_index'], 'attempts_paragraph_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            $table->dropIndex('attempts_paragraph_lookup');
            $table->dropColumn('paragraph_index');
        });
    }
};
