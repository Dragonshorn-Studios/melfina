<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_message_id')->constrained('agent_messages')->cascadeOnDelete();
            // The path/name the sender attached plus the content captured at send
            // time — an immutable snapshot, never re-read from the VFS.
            $table->string('name', 512);
            $table->text('content');
            $table->timestamps();

            $table->index('agent_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_message_attachments');
    }
};
