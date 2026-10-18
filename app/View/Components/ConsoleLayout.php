<?php

namespace App\View\Components;

use App\Models\Agent;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

class ConsoleLayout extends Component
{
    /**
     * Every console page renders the sidebar, so the agent list (with file
     * counts) is loaded once here for all views.
     *
     * @var Collection<int, Agent>
     */
    public Collection $agents;

    public function __construct(
        public ?string $title = null,
        public ?Agent $agent = null,
        public bool $bare = false,
    ) {
        // Guest pages and the schema setup screen share this layout but
        // render only a bare header — setup must not query tables that
        // pending migrations may still be adding.
        $this->agents = (! $this->bare && auth()->check())
            ? Agent::query()->withCount('files')->orderBy('name')->get()
            : collect();
    }

    public function render(): View
    {
        return view('components.console-layout');
    }
}
