# Mock payment integration

## Local configuration

In the ignored local `techreserve\.env`, configure:

```ini
paymentGateway.baseUrl = http://127.0.0.1:8082
paymentGateway.apiKey = <the same local mock API key configured in the gateway>
```

In the ignored local `mock-payment-gateway\.env`, set the same API key and:

```ini
paymentGateway.techReserveReturnUrl = http://127.0.0.1:8083/borrower/payments/return
```

Never put key values in source control or expose them to the browser. The
gateway's fixed return URL is informational navigation only; the payment
reference is added by its checkout page, and TechReserve requires the borrower
to be authenticated and to own that payment before checking status.

## Flow and persisted states

Only the owner of an approved reservation can start payment. TechReserve reads
the persisted reservation total, resource snapshots, and borrower identity in a
short transaction, stores a local pending attempt, commits, then calls the
gateway. The gateway API uses `POST /api/v1/payments` with `X-API-Key`, and its
hosted checkout simulates Mock GCash or Mock Bank Transfer. Calls are bounded
to five seconds overall and two seconds to connect.

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

Database-backed tests, concurrent request checks, and end-to-end testing remain
unverified until the isolated test setup is safe to use.
