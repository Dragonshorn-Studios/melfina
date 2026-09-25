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
        $this->agents = Agent::query()
            ->withCount('files')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('components.console-layout');
    }
}
