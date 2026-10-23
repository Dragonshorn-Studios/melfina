<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminApiKey;
use App\Models\Agent;
use App\Models\KnowledgeIdentifierKind;
use App\Models\SharedSpace;
use App\Models\VfsFile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('console.dashboard', [
            'owner' => $request->user(),
            'fleetKeys' => $request->user()->mcpFleetKeys()->latest()->get(),
            'enrollKeys' => $request->user()->agentEnrollKeys()->latest()->get(),
            'adminKeys' => $request->user()->adminApiKeys()->latest()->get(),
            'adminPermissions' => AdminApiKey::permissionChoices(),
            'operatorPersona' => $request->user()->operator_persona,
            'operatorEntry' => $request->user()->operatorEntry?->load([
                'identifiers' => fn ($query) => $query->orderBy('kind')->orderBy('id'),
            ]),
            'identifierKinds' => KnowledgeIdentifierKind::cases(),
            'stats' => [
                'agents' => Agent::count(),
                'active' => Agent::where('status', Agent::STATUS_ACTIVE)->count(),
                'files' => VfsFile::count(),
                'spaces' => SharedSpace::count(),
            ],
        ]);
    }
}
