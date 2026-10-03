<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student notifications: badges a teacher gave and what a teacher said about
 * a reading. Who gave a badge is kept on the award, and the student's last
 * look at their notifications decides which ones are new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('notifications_read_at')->nullable();
        });

        Schema::table('student_badges', function (Blueprint $table) {
            $table->foreignId('awarded_by')->nullable()->after('earned_at')
                ->constrained('teachers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_badges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('awarded_by');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('notifications_read_at');
        });
    }
};
