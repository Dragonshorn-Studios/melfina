<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('mime')->nullable()->after('content');
            $table->binary('content_blob')->nullable()->after('mime');
        });

        Schema::table('shared_files', function (Blueprint $table) {
            $table->string('mime')->nullable()->after('content');
            $table->binary('content_blob')->nullable()->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['mime', 'content_blob']);
        });

        Schema::table('shared_files', function (Blueprint $table) {
            $table->dropColumn(['mime', 'content_blob']);
        });
    }
};
