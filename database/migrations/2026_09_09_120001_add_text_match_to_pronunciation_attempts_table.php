<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            // How much of what the pupil said matches the page (0-100), and
            // whether that was low enough to call the reading off-script.
            // pron_score is capped by this match, so a pupil who reads
            // something else can never pass on Azure's alignment score alone.
            $table->decimal('text_match_score', 5, 2)->nullable()->after('pron_score');
            $table->boolean('is_off_script')->default(false)->after('text_match_score');
        });
    }

    public function down(): void
    {
        Schema::table('pronunciation_attempts', function (Blueprint $table) {
            $table->dropColumn(['text_match_score', 'is_off_script']);
        });
    }
};
