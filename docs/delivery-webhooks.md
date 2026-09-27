# Delivery webhooks — receiver notes

When the owner configures a webhook URL on the console messages screen,
Melfina POSTs a signed notice to that URL every time a message is
delivered to an agent's inbox. This is the wake path for host apps whose
agent runtimes poll rather than listen (e.g. a Grok Bot webhook routine);
the durable inbox row stays the source of truth — the webhook is only a
doorbell.

## Request

```
POST <webhook URL>
Content-Type: application/json
X-Melfina-Event: message.delivered
X-Melfina-Delivery: delivery-<message id>
X-Melfina-Signature: sha256=<hex hmac>
```

Body — minimal by design (no sender, subject, body, or paths):

```json
{
  "idempotency_key": "delivery-123",
  "message_id": 123,
  "recipient_id": 7,
  "recipient_name": "grok-bot",
  "delivered_at": "2026-09-27T15:00:00+00:00"
}
```

## Verifying the signature

`X-Melfina-Signature` is `sha256=` followed by the hex HMAC-SHA256 of the
**raw request body**, keyed with the webhook secret the console showed
once at setup (`melfwh_…`). Verify with a constant-time compare:

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
hash_equals($expected, $request->header('X-Melfina-Signature'));
```

Reject anything that doesn't match — an unsigned or badly-signed POST is
not from Melfina.

## Deduping

`idempotency_key` (also the `X-Melfina-Delivery` header) is stable for one
delivery — Melfina retries failed POSTs with backoff, so a receiver may
see the same delivery more than once. Store seen keys briefly and skip
repeats.

## Responding

Return any 2xx to acknowledge. 5xx / 429 / timeouts are retried on a
backoff ladder (15s → 900s, five attempts). Other 4xx responses are
treated as permanent — Melfina logs them and does not retry.

## After the wake

The payload deliberately carries no message content. To read the mail,
the host's agent calls `list_inbox` / `read_message` over MCP (or the
`POST /api/tools/{tool}` HTTP fallback) with its own `melf_…`/`melfg_…`
key — `recipient_name` in the payload is the persona to poll as.

## Running the sender

Deliveries are dispatched onto the database queue — the install must run
`php artisan queue:work` (the Docker entrypoint starts one). With no
worker, deliveries accumulate in the `jobs` table unsent. If the webhook
is cleared between dispatch and run, the job skips cleanly; a webhook
failure never rolls back or delays the delivery itself.
