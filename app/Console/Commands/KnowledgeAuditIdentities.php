<?php

namespace App\Console\Commands;

use App\Models\AgentEntityReference;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\User;
use App\Services\KnowledgeIdentityAdmin;
use Illuminate\Console\Command;

class KnowledgeAuditIdentities extends Command
{
    protected $signature = 'knowledge:audit-identities';

    protected $description = 'Report operator binding, identifier collisions, scoped references, and redirects';

    public function handle(KnowledgeIdentityAdmin $admin): int
    {
        $owners = User::query()->where('is_owner', true)->get(['id', 'email', 'operator_entry_id']);
        $this->info('Owners: '.$owners->count());
        foreach ($owners as $owner) {
            $this->line("  user {$owner->id} operator_entry_id=".($owner->operator_entry_id ?? 'null'));
        }

        $collisions = $admin->identifierCollisions();
        $this->info('Identifier collisions: '.count($collisions));
        foreach ($collisions as $collision) {
            $this->line('  '.$collision['normalized'].' → '.implode(', ', $collision['entry_ids']));
        }

        $this->info('Scoped references: '.AgentEntityReference::query()->count());
        $this->info('Redirects: '.KnowledgeEntryRedirect::query()->count());
        $this->info('People: '.KnowledgeEntry::query()->where('type', 'person')->count());

        return self::SUCCESS;
    }
}
