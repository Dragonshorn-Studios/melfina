<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The console and the admin API both validate an agent's description
     * at 500 characters, but the column kept Laravel's default 255 — a
     * contract-valid blurb failed the INSERT with a QueryException on
     * strict databases (invisible on SQLite, which ignores lengths).
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->change();
        });
    }

    /**
     * Irreversible on purpose: narrowing back to 255 would fail (strict
     * databases) or silently truncate rows already storing a contract-
     * valid 500-character description, so rollback leaves the widened
     * column in place.
     */
    public function down(): void
    {
        //
    }
};
