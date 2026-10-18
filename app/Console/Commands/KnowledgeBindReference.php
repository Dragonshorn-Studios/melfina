<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentEntityReferenceKind;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use Illuminate\Console\Command;

class KnowledgeBindReference extends Command
{
    protected $signature = 'knowledge:bind-reference
        {entry : Canonical name or numeric entry id of the person}
        {value : Contextual title (e.g. a form of address)}
        {--agent=* : Agent name (repeatable)}
        {--all-active : Bind for every active agent}
        {--kind=address_term : address_term or contextual_name}
        {--unbind : Remove the title from the selected agents}';

    protected $description = 'Bind a contextual title for selected imps to an existing person. Does not create a global alias.';

    public function handle(KnowledgeIdentityAdmin $admin, KnowledgeIdentityResolver $resolver): int
    {
        $agents = $this->selectedAgents();
        if ($agents === []) {
            $this->error('Select --agent=name or --all-active.');

            return self::FAILURE;
        }

        $value = (string) $this->argument('value');

        if ($this->option('unbind')) {
            foreach ($agents as $agent) {
                $admin->unbindReference($agent, $value);
            }
            $this->info('Removed the contextual reference from '.count($agents).' agent(s).');

            return self::SUCCESS;
        }

        $raw = (string) $this->argument('entry');
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

        $kind = AgentEntityReferenceKind::tryFrom((string) $this->option('kind')) ?? AgentEntityReferenceKind::AddressTerm;
        $admin->bindReference($agents, $resolved->entry, $value, $kind);
        $this->info("Bound '{$value}' → {$resolved->entry->name} for ".count($agents).' agent(s).');

        return self::SUCCESS;
    }

    /**
     * @return list<Agent>
     */
    private function selectedAgents(): array
    {
        if ($this->option('all-active')) {
            return Agent::query()->where('status', Agent::STATUS_ACTIVE)->orderBy('name')->get()->all();
        }

        $names = $this->option('agent');
        if (! is_array($names) || $names === []) {
            return [];
        }

        return Agent::query()->whereIn('name', $names)->get()->all();
    }
}
