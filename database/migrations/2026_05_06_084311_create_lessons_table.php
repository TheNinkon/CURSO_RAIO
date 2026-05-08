<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('section_title')->default('Contenido');
            $table->unsignedInteger('section_order')->default(1);
            $table->unsignedInteger('position')->default(1);
            $table->text('summary')->nullable();
            $table->longText('notes_markdown')->nullable();
            $table->json('resource_links')->nullable();
            $table->string('video_path')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('subtitle_es_path')->nullable();
            $table->string('subtitle_en_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_available')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
            $table->index(['course_id', 'section_order', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
