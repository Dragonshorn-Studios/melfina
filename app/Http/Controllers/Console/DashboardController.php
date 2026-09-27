<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
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
            'stats' => [
                'agents' => Agent::count(),
                'active' => Agent::where('status', Agent::STATUS_ACTIVE)->count(),
                'files' => VfsFile::count(),
                'spaces' => SharedSpace::count(),
            ],
            'agents' => Agent::query()
                ->withCount('files')
                ->withMax('files', 'updated_at')
                ->orderBy('name')
                ->get(),
            'spaces' => SharedSpace::query()
                ->withCount(['files', 'aclEntries'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
