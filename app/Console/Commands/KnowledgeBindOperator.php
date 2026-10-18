<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use Illuminate\Console\Command;

class KnowledgeBindOperator extends Command
{
    protected $signature = 'knowledge:bind-operator {entry? : Canonical name, alias, or numeric entry id} {--clear : Remove the operator person binding}';

    protected $description = 'Bind the installation operator to an existing person in the knowledge graph';

    public function handle(KnowledgeIdentityAdmin $admin, KnowledgeIdentityResolver $resolver): int
    {
        $owner = User::query()->where('is_owner', true)->get();

        if ($owner->count() !== 1) {
            $this->error('Expected exactly one installation owner; refusing to guess.');

            return self::FAILURE;
        }

        $user = $owner->first();

        if ($this->option('clear')) {
            $admin->unbindOperator($user);
            $this->info('Operator person binding cleared.');

            return self::SUCCESS;
        }

        $raw = (string) $this->argument('entry');
        if ($raw === '') {
            $this->error('Pass an entry name or id, or --clear.');

            return self::FAILURE;
        }
        $entryId = ctype_digit($raw) ? (int) $raw : null;
        $resolved = $resolver->resolve(
            KnowledgeIdentityContext::forOwner(),
            query: $entryId === null ? $raw : null,
            entryId: $entryId,
        );

        if (! $resolved->found()) {
            $this->error($resolved->error ?? 'No matching person.');

            return self::FAILURE;
        }

        $admin->bindOperator($resolved->entry, $user);
        $this->info("Operator bound to {$resolved->entry->name} (id {$resolved->entry->id}).");

        return self::SUCCESS;
    }
}
