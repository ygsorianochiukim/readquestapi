<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            // Diction — how clearly each sound was articulated. The mean
            // phoneme accuracy across the words the pupil actually said, so it
            // is a different question from word accuracy ("was it the right
            // word?"). Null on attempts scored before phonemes were asked for.
            $table->decimal('diction_score', 5, 2)->nullable()->after('prosody_score');
        });

        Schema::table('pronunciation_words', function (Blueprint $table) {
            // A pupil's second go at one word, after tapping it. Kept apart
            // from accuracy_score: the retry shows the word was fixed, it does
            // not rewrite what happened in the reading itself.
            $table->decimal('retry_accuracy', 5, 2)->nullable()->after('duration_ms');
            $table->timestamp('retried_at')->nullable()->after('retry_accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('pronunciation_words', function (Blueprint $table) {
            $table->dropColumn(['retry_accuracy', 'retried_at']);
        });

        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            $table->dropColumn('diction_score');
        });
    }
};
