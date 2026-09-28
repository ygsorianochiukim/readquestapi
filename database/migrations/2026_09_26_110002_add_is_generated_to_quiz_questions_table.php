<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quiz questions are written for the teacher from the chapter text now. The
 * flag tells a generated question (safe to regenerate) from one a teacher has
 * edited or wrote before generation existed (never overwritten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->boolean('is_generated')->default(false)->after('correct_answer');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn('is_generated');
        });
    }
};
