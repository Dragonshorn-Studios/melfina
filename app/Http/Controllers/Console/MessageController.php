<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MessageController extends Controller
{
    /**
     * Fleet-wide message history, newest first. Filters: `agent` narrows to
     * deliveries involving one agent, `direction` picks that agent's inbox
     * or sent side, `read` and `ack` filter the recipient's state.
     * Read-only — the owner inspects but never mutates message state.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AgentMessage::class);

        $validated = $request->validate([
            'agent' => ['nullable', 'integer', 'exists:agents,id'],
            'direction' => ['nullable', 'in:inbox,sent'],
            'read' => ['nullable', 'in:unread,read'],
            'ack' => ['nullable', 'in:unacked,acked'],
        ]);

        $agentId = isset($validated['agent']) ? (int) $validated['agent'] : null;
        $direction = $validated['direction'] ?? null;

        $messages = AgentMessage::query()
            ->withCount('attachments')
            ->when($agentId && $direction === 'inbox', fn (Builder $q) => $q->where('recipient_agent_id', $agentId))
            ->when($agentId && $direction === 'sent', fn (Builder $q) => $q->where('sender_agent_id', $agentId))
            ->when($agentId && $direction === null, fn (Builder $q) => $q->where(function (Builder $q) use ($agentId) {
                $q->where('sender_agent_id', $agentId)->orWhere('recipient_agent_id', $agentId);
            }))
            ->when(($validated['read'] ?? null) === 'unread', fn (Builder $q) => $q->whereNull('read_at'))
            ->when(($validated['read'] ?? null) === 'read', fn (Builder $q) => $q->whereNotNull('read_at'))
            ->when(($validated['ack'] ?? null) === 'unacked', fn (Builder $q) => $q->whereNull('acknowledged_at'))
            ->when(($validated['ack'] ?? null) === 'acked', fn (Builder $q) => $q->whereNotNull('acknowledged_at'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('console.messages.index', [
            'label' => AgentMessage::messagesLabel(),
            'messages' => $messages,
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'agent' => $agentId,
                'direction' => $direction,
                'read' => $validated['read'] ?? null,
                'ack' => $validated['ack'] ?? null,
            ],
        ]);
    }

    /**
     * Full message with attachment snapshots. Never marks read or acked —
     * those are the recipient agent's own state transitions.
     */
    public function show(AgentMessage $message): View
    {
        $this->authorize('view', $message);

        return view('console.messages.show', [
            'label' => AgentMessage::messagesLabel(),
            'message' => $message->loadMissing('attachments'),
        ]);
    }
}
