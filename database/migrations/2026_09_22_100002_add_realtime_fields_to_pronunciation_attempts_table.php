<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            // Prosody — intonation — is a fourth Azure dimension alongside
            // accuracy, fluency and completeness. It only comes back when the
            // assessment asks for it.
            $table->decimal('prosody_score', 5, 2)->nullable()->after('completeness_score');

            // Reading pace, measured from Azure's word offsets rather than from
            // wall-clock time, so leading and trailing silence does not count.
            $table->unsignedInteger('words_per_minute')->nullable()->after('is_off_script');
            $table->string('pace')->nullable()->after('words_per_minute'); // too_slow|good|too_fast
            $table->unsignedInteger('duration_ms')->nullable()->after('pace');

            // A teacher's manual verdict. Null means "the automatic score
            // stands"; anything else overrides it everywhere it is shown.
            $table->decimal('teacher_score', 5, 2)->nullable()->after('duration_ms');
            $table->text('teacher_note')->nullable()->after('teacher_score');
        });
    }

    public function down(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'prosody_score',
                'words_per_minute',
                'pace',
                'duration_ms',
                'teacher_score',
                'teacher_note',
            ]);
        });
    }
};
