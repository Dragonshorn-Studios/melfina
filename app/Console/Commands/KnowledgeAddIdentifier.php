<?php

namespace App\Console\Commands;

use App\Models\KnowledgeIdentifierKind;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use Illuminate\Console\Command;

class KnowledgeAddIdentifier extends Command
{
    protected $signature = 'knowledge:add-identifier
        {entry : Canonical name or numeric entry id}
        {value : The additional name}
        {--kind=alias : alias, handle, or persona}';

    protected $description = 'Add a global identifier (alias, handle, or human persona name) to a knowledge entry';

    public function handle(KnowledgeIdentityAdmin $admin, KnowledgeIdentityResolver $resolver): int
    {
        $raw = (string) $this->argument('entry');
        $entryId = ctype_digit($raw) ? (int) $raw : null;
        $resolved = $resolver->resolve(
            KnowledgeIdentityContext::forOwner(),
            query: $entryId === null ? $raw : null,
            entryId: $entryId,
        );

        if (! $resolved->found()) {
            $this->error($resolved->error ?? 'No matching entry.');

            return self::FAILURE;
        }

        $kind = KnowledgeIdentifierKind::tryFrom((string) $this->option('kind'));
        if ($kind === null) {
            $this->error('Kind must be alias, handle, or persona.');

            return self::FAILURE;
        }

        $identifier = $admin->addIdentifier($resolved->entry, (string) $this->argument('value'), $kind);
        $this->info("Stored {$identifier->kind->value} '{$identifier->value}' on {$resolved->entry->name}.");

        return self::SUCCESS;
    }
}
