<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entry_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('author');
            $table->timestamps();

            $table->index(['knowledge_entry_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entry_versions');
    }
};
