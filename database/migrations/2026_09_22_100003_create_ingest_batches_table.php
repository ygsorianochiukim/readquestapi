<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            // The draft book this upload is filling. Created up front so pages
            // have somewhere to land as they are read, and only published when
            // the teacher approves the preview.
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();

            $table->string('source_name');           // the file the teacher chose
            $table->string('source_path')->nullable(); // kept so a failed run can be retried
            $table->string('source_type');           // pdf | images

            // queued -> rasterizing -> reading -> ready -> committed | failed
            $table->string('status')->default('queued');
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_done')->default(0);
            // Set when the pages could not be turned into images and only their
            // text could be recovered — the preview has to say so.
            $table->boolean('text_only')->default(false);
            $table->text('error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_batches');
    }
};
