<?php

namespace App\Console\Commands;

use App\Services\KnowledgeIdentityAdmin;
use Illuminate\Console\Command;

class KnowledgeBackfillIdentifiers extends Command
{
    protected $signature = 'knowledge:backfill-identifiers';

    protected $description = 'Copy JSON aliases into knowledge_identifiers and report name collisions';

    public function handle(KnowledgeIdentityAdmin $admin): int
    {
        $report = $admin->backfillAliases();

        $this->info("Created {$report['created']} identifier rows; skipped {$report['skipped']}.");

        if ($report['collisions'] === []) {
            $this->info('No colliding names.');

            return self::SUCCESS;
        }

        $this->warn('Names that identify more than one entry:');
        foreach ($report['collisions'] as $collision) {
            $this->line('  '.$collision['normalized'].' → '.implode(', ', $collision['entry_ids']));
        }

        return self::SUCCESS;
    }
}
