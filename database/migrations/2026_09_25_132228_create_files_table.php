<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('path', 512);
            $table->longText('content');
            $table->timestamps();

            $table->unique(['agent_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
