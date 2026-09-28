<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (student, chapter, game type) the first time that mini-game
     * is won. Points are only ever awarded on insert, so replaying a game can
     * never farm them — the row is the receipt.
     */
    public function up(): void
    {
        Schema::create('chapter_game_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();
            $table->string('game_type'); // scramble | missing-word | sentence-builder
            $table->unsignedSmallInteger('points_awarded')->default(0);
            $table->boolean('perfect')->default(false); // won with no mistakes first time
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'chapter_id', 'game_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_game_results');
    }
};
