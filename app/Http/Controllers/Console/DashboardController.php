<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    /**
     * The console is the four tabs — Agents is the landing one.
     */
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('console.agents.index');
    }
}
