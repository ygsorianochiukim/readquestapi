<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-page progress for page-based (scanned) books, mirroring
     * reading_progress for chapter-based ones.
     */
    public function up(): void
    {
        Schema::create('page_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('book_page_id')->constrained('book_pages')->cascadeOnDelete();
            $table->boolean('is_read')->default(false);
            $table->boolean('pronunciation_passed')->default(false);
            $table->unsignedTinyInteger('best_score')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'book_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_progress');
    }
};
