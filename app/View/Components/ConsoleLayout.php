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
    ) {
        // Guest pages share this layout but render only a bare header.
        $this->agents = auth()->check()
            ? Agent::query()->withCount('files')->orderBy('name')->get()
            : collect();
    }

    public function render(): View
    {
        return view('components.console-layout');
    }
}
