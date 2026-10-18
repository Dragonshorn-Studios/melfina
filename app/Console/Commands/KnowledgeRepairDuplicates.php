<?php

namespace App\Console\Commands;

use App\Services\KnowledgeIdentityAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KnowledgeRepairDuplicates extends Command
{
    protected $signature = 'knowledge:repair-duplicates
        {manifest : Path to a JSON mapping of canonical_id and source_ids}
        {--execute : Apply the mapping (default is dry-run)}';

    protected $description = 'Redirect duplicate knowledge entries onto a canonical id using an owner-approved manifest. Dry-run by default.';

    public function handle(KnowledgeIdentityAdmin $admin): int
    {
        $path = (string) $this->argument('manifest');
        if (! File::isReadable($path)) {
            $this->error("Cannot read manifest {$path}");

            return self::FAILURE;
        }

        $manifest = json_decode(File::get($path), true);
        if (! is_array($manifest)) {
            $this->error('Manifest is not JSON object.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('execute');
        $report = $admin->repairDuplicates($manifest, $dryRun);

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($dryRun) {
            $this->comment('Dry-run only. Re-run with --execute after reviewing the report.');
        }

        return self::SUCCESS;
    }
}
