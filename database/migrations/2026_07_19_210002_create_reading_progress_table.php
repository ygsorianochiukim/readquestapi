<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (student, chapter). Tracks the four chapter activities and
        // the resulting completion/unlock state used for progression gating.
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();

            $table->string('status')->default('not_started'); // not_started | in_progress | completed
            $table->boolean('is_unlocked')->default(false);

            // The required chapter activities (story + pronunciation + game + quiz).
            $table->boolean('story_read')->default(false);
            $table->boolean('pronunciation_passed')->default(false);
            $table->boolean('game_completed')->default(false);
            $table->boolean('quiz_passed')->default(false);
            $table->unsignedTinyInteger('quiz_score')->nullable(); // best quiz score (percentage)

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'chapter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};
