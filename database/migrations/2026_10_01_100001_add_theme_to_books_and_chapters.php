<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // One of App\Domain\Theme\Themes::KEYS; null reads in the default look.
            $table->string('theme', 32)->nullable()->after('type');
        });

        Schema::table('chapters', function (Blueprint $table) {
            // Null: the chapter is read in its book's theme.
            $table->string('theme', 32)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('theme');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
