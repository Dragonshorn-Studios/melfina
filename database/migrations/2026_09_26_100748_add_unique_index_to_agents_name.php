<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent names are the fleet persona identity — enforce uniqueness at the
     * database level (form validation is check-then-act and races). Installs
     * with pre-existing duplicates get the later rows renamed `{name}~{id}`:
     * the persona would have failed closed anyway, so nothing that worked
     * changes hands.
     */
    public function up(): void
    {
        $duplicates = DB::table('agents')
            ->select('name', DB::raw('MIN(id) as keeper'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('keeper', 'name');

        foreach ($duplicates as $name => $keeper) {
            $ids = DB::table('agents')
                ->where('name', $name)
                ->where('id', '!=', $keeper)
                ->pluck('id');

            foreach ($ids as $id) {
                DB::table('agents')->where('id', $id)->update(['name' => $name.'~'.$id]);
            }

            Log::warning('Duplicate agent names renamed during agents.name unique-index migration', ['name' => $name]);
        }

        Schema::table('agents', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
