<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_lower')->unique();
            $table->string('type');
            $table->json('aliases');
            $table->string('summary', 500);
            $table->text('state');
            $table->json('related');
            $table->string('source')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('updated_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
    }
};
