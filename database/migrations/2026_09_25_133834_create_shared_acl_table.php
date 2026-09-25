<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_acl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_space_id')->constrained('shared_spaces')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 16);
            $table->timestamps();

            $table->unique(['shared_space_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_acl');
    }
};
