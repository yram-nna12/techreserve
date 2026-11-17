# Mock payment integration

## Local configuration

In the ignored local `techreserve\.env`, configure:

```ini
paymentGateway.baseUrl = http://127.0.0.1:8082
paymentGateway.apiKey = <the same local mock API key configured in the gateway>
paymentGateway.webhookSecret = <a separate random secret of at least 32 characters>
paymentGateway.webhookTimestampToleranceSeconds = 300
```

In the ignored local `mock-payment-gateway\.env`, set the same API key and:

```ini
paymentGateway.techReserveReturnUrl = http://127.0.0.1:8083/borrower/payments/return
```

Never put key values in source control or expose them to the browser. The
gateway's fixed return URL is informational navigation only; the payment
reference is added by its checkout page, and TechReserve requires the borrower
to be authenticated and to own that payment before checking status.

## Payment webhook receiver (Phase 1)

TechReserve exposes `POST /api/v1/webhooks/payments`. The gateway API key is
not used for webhook authentication. The receiver uses a separate shared
`paymentGateway.webhookSecret`, requires at least 32 characters, and accepts a
timestamp within `paymentGateway.webhookTimestampToleranceSeconds` (30–900,
default 300).

The sender provides these headers:

```text
X-Payment-Event-Id: <UUID>
X-Payment-Timestamp: <Unix timestamp in seconds>
X-Payment-Signature: v1=<lowercase HMAC-SHA256 hex>
```

The HMAC input is the UTF-8 bytes of
`timestamp + "\n" + event-id + "\n" + exact-raw-request-body`, signed with the
shared webhook secret. The JSON body is versioned (`event_version: 1`) and
contains `event_id`, `event_type: "payment.status.changed"`,
`occurred_at`, transaction and reservation references, gateway transaction
ID, amount, currency, payment method, status, `paid_at`, and `expires_at`.
Only `paid`, `failed`, and `expired` are accepted as webhook statuses.

The receiver compares the signed event to the saved payment and records a
unique event ID and SHA-256 body hash with the payment-state update. An exact
replay is acknowledged without applying the state or notification again;
reuse of an ID with different content and conflicting terminal states are
rejected. The additive `payment_webhook_receipts` migration must be applied
before the endpoint can persist an event. It has been created but **not run**.
The gateway sender/outbox worker is implemented in Phase 2 code, but its
gateway lease/event-ID migration and the TechReserve receipt migration have
not been executed. Configure the shared signing secret only in the two local
ignored `.env` files before enabling delivery.

## Flow and persisted states

Only the owner of an approved reservation whose latest teacher-signed form has
been accepted can start or resume payment. The form must be uploaded as PDF,
JPG, or PNG and is manually reviewed by an administrator; its private storage
and review workflow are documented in `RESERVATIONS.md`. TechReserve reads the
persisted reservation total, resource snapshots, and borrower identity in a
short transaction, stores a local pending attempt, commits, then calls the
gateway. Matching equipment is sent as grouped catalog items while each
reservation retains its underlying inventory allocations. The gateway API
uses `POST /api/v1/payments` with `X-API-Key`, and its hosted checkout simulates
Mock GCash or Mock Bank Transfer. Calls are bounded to five seconds overall
and two seconds to connect.

The borrower return link calls the authenticated gateway's
`GET /api/v1/payments/{transaction_reference}` endpoint. TechReserve verifies
the returned reference, gateway transaction ID, amount, currency, method, and
status before updating the payment row. Reservation approval remains separate
from payment state.

Local statuses are `pending`, `paid`, `failed`, `expired`, and the existing
`refunded` value. No refund workflow is implemented. Pending includes an
unknown outcome: it blocks cancellation and another payment attempt until the
gateway confirms a terminal result. Failed or expired attempts remain in
history and permit a new attempt. A gateway outage, timeout, malformed
response, or status 404 does not mark a payment failed. Retrying initiation
reuses the persisted request payload and transaction reference, relying on
gateway idempotency; it never creates a second local attempt while one is
unresolved.

## Required database migration

The existing `CreatePaymentsTable` migration already defines unique payment
references and gateway transaction IDs, amount/currency snapshots, status,
method, and timestamps. Apply the new additive migration
`2026-11-17-000004_AddPaymentGatewayReplayFields` only after a verified backup
and explicit approval. It adds the replay request snapshot, checkout URL,
verification timestamp, and a unique nullable active-reservation key to
serialize unresolved attempts. It refuses to run if historical data has more
than one pending attempt for a reservation. No migration was run as part of
this implementation.

## Manual verification after database safety approval

1. Confirm the verified backup and approved database target. Do not use the
   development database for integration tests.
2. Apply TechReserve migrations only after the MariaDB privilege-table issue
   has been resolved and the isolated test database has been independently
   verified.
3. Configure the matching API key in both local `.env` files and the gateway
   return URL above. Start the existing apps on ports 8083 and 8082.
4. As a borrower, verify pending reservations show no payment action. On an
   approved reservation, start Mock GCash or Mock Bank Transfer.
5. On gateway checkout, simulate success and use the return link. Confirm
   TechReserve verifies the gateway and displays `paid` with both transaction
   references.
6. Repeat with simulated failure; confirm the failed attempt remains in
   history and a new attempt can be started.
7. Leave checkout pending or disconnect the gateway; confirm status stays
   pending and borrower cancellation/new attempts remain blocked. Restore the
   gateway, check status, and verify a definitive `expired` result permits a
   new attempt.
8. Sign in as a different borrower and as an administrator. Confirm ownership
   is enforced, administrators can view status/references only, and the API
   key is never present in browser output.

Webhook authentication and payload validation have isolated unit tests.
Database-backed receipt/replay tests, concurrent request checks, and end-to-end
testing remain unverified until the isolated test setup is safe to use.

## Automatic payment reconciliation (Phase 3)

Run `php spark payments:reconcile` from the TechReserve project directory to
check up to 25 stale pending attempts. An optional integer argument can lower
or raise the batch size (1–100). The command is intended for a scheduler; it
does not run continuously.

The default stale interval is 10 minutes. A payment is eligible only when its
latest available `last_verified_at`, `updated_at`, or `created_at` timestamp is
at least that old. Verified `pending` responses refresh the verification time
through the shared payment-state updater, preventing frequent repeat polling.
Failed checks update the existing `updated_at` timestamp as a retry cooldown;
they do not change payment status or mark `last_verified_at` as verified.

The worker acquires a MariaDB advisory lock to prevent overlapping
reconciliation runs, polls the authenticated status endpoint with the existing
5-second overall/2-second connect timeouts, and retries retryable network,
408, 429, and 5xx failures at most twice by default. A continuing availability
failure stops the batch; 404 results leave the attempt pending and are
throttled by the cooldown timestamp. The shared locked updater prevents a
webhook/reconciliation race from changing a terminal state. Sanitized outcome
counts and failure codes are reported; credentials and payment payloads are
not logged.

Settings can be overridden in the ignored local `.env`:

```ini
paymentGateway.reconciliationStaleMinutes = 10
paymentGateway.reconciliationBatchLimit = 25
paymentGateway.reconciliationMaxAttempts = 2
paymentGateway.reconciliationRetryDelayMilliseconds = 250
```

The reconciliation query uses existing payment timestamps and requires the
`last_verified_at` column from
`2026-11-17-000004_AddPaymentGatewayReplayFields`. That migration and the
webhook receipt migration are still unapplied. No migration or database-backed
reconciliation test was run for this implementation.

## Transactional payment notifications (Phase 4)

Verified transitions from `pending` to `paid`, `failed`, or `expired` now
persist their in-app notification through the same TechReserve connection and
inside the payment-state transaction. Notification event keys remain
`payment:{payment_id}:{status}`, backed by the existing unique
`(user_id, event_key)` index. Exact webhook replays, repeated polling, and
already-applied terminal statuses do not create another notification.

Payment notification persistence is strict: a missing/inactive borrower, a
disabled notification setting, or a storage error is logged without payment
details and aborts the transaction, allowing the gateway webhook to retry.
This path writes only the in-app notification; it neither sends email nor
queues an email delivery. Reservation submission/approval/rejection dispatch
behavior is unchanged. The existing notification event-key migration must be
applied before this path can persist events. No migrations or database writes
were performed for Phase 4.
