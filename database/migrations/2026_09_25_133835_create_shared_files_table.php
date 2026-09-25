<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_space_id')->constrained('shared_spaces')->cascadeOnDelete();
            $table->string('path', 512);
            $table->longText('content');
            $table->timestamps();

            $table->unique(['shared_space_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_files');
    }
};
