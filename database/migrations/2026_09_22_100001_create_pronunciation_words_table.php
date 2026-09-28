<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pronunciation_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pronunciation_attempt_id')
                ->constrained('pronunciation_attempts')
                ->cascadeOnDelete();

            // Position in the reference text, so the reader can colour the page
            // back in without re-matching the words.
            $table->unsignedInteger('word_index');
            $table->string('word');
            $table->decimal('accuracy_score', 5, 2)->nullable();

            // Azure's ErrorType: None, Mispronunciation, Omission, Insertion,
            // UnexpectedBreak, MissingBreak, Monotone.
            $table->string('error_type')->default('None');

            // Where the word sat in the recording — what makes "play just this
            // word back" and the pace calculation possible.
            $table->unsignedInteger('offset_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['pronunciation_attempt_id', 'word_index']);
            // The teacher report groups every miss a pupil has ever made by
            // word, so that lookup must not table-scan.
            $table->index(['error_type', 'word']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronunciation_words');
    }
};
