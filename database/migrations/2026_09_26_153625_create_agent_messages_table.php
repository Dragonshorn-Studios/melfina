<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            // Nullable FKs + name snapshots: deleting an agent keeps delivery history readable.
            $table->foreignId('sender_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('recipient_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('sender_name');
            $table->string('recipient_name');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_agent_id', 'id']);
            $table->index(['sender_agent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
    }
};
