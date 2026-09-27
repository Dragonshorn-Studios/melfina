<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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
            'webhookUrl' => $request->user()->webhook_url,
            'webhookSecretSet' => $request->user()->webhook_secret !== null,
        ]);
    }

    /**
     * Set or clear the delivery webhook URL. Saving a URL auto-mints a
     * signing secret when none exists — the plaintext flashes once so the
     * owner can wire it into the receiver.
     */
    public function updateWebhook(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', AgentMessage::class);

        $validated = $request->validate([
            'webhook_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $user = $request->user();
        $url = trim((string) ($validated['webhook_url'] ?? ''));

        if ($url === '') {
            $user->webhook_url = null;
            $user->webhook_secret = null;
            $user->save();

            return redirect()
                ->route('console.messages.index')
                ->with('status', 'Delivery webhook cleared.');
        }

        $user->webhook_url = $url;
        $user->save();

        if ($user->webhook_secret === null) {
            return redirect()
                ->route('console.messages.index')
                ->with('status', 'Delivery webhook saved — copy the signing secret into your receiver.')
                ->with('plaintext_webhook_secret', $user->rotateWebhookSecret());
        }

        return redirect()
            ->route('console.messages.index')
            ->with('status', 'Delivery webhook saved.');
    }

    /**
     * Roll the HMAC secret. The new plaintext flashes once; the old one
     * stops verifying immediately, so receivers must be updated.
     */
    public function rotateWebhookSecret(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', AgentMessage::class);

        return redirect()
            ->route('console.messages.index')
            ->with('status', 'Webhook secret rotated — update your receiver.')
            ->with('plaintext_webhook_secret', $request->user()->rotateWebhookSecret());
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
