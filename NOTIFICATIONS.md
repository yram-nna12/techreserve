# TechReserve notifications

Phase 6 adds in-app notifications and a database-backed email outbox for committed reservation and payment events.

## Events and recipients

- New reservation submission: active administrators.
- Reservation approved, rejected, cancelled, or completed: the affected borrower.
- Payment confirmed paid, definitively failed, or expired: the affected borrower.
- Pending payment checks and gateway/network timeouts do not create payment notifications.
- Reservation submission replays and repeated status verification use stable per-recipient event keys and do not create duplicates.

Borrower email addresses are taken only from active accounts registered using the institutional address returned by Student Records. Administrator addresses are taken only from active office accounts provisioned by TechReserve. There is no separate email-verification column in the current schema.

## Database migration

`2026-11-17-000005_AddNotificationEventOutbox` is prepared but has **not** been executed. It adds a nullable event key and recipient/event uniqueness to the existing `notifications` table, plus `notification_email_outbox` with delivery state, attempts, scheduling, and deduplication fields. Do not apply it until database health, backups, and approval are confirmed.

Notification dispatch occurs after reservation/payment transactions commit. Notification or email-queue storage errors are logged without rolling back business state. Once queued, an email remains durable and can be retried by the worker. There is a small process-failure window between the business commit and the post-commit notification insert; these events are not reconstructed automatically.

## Safe defaults

Both settings default to `false`:

```ini
notifications.enabled = false
notifications.emailDeliveryEnabled = false
```

The first setting gates notification creation and read-state changes. Email delivery requires both settings to be true. With delivery disabled, the CLI command exits before reading or changing the outbox. No real SMTP settings are included.

## SMTP configuration

After the database is approved and the outbox migration is applied, configure these values in the ignored local `.env`, not in tracked files:

```ini
email.protocol = smtp
email.fromEmail =
email.fromName = TechReserve
email.SMTPHost =
email.SMTPPort = 587
email.SMTPUser =
email.SMTPPass =
email.SMTPCrypto = tls
email.SMTPAuthMethod = login
email.SMTPTimeout = 10
```

Use the SMTP provider's required encryption/port combination. Credentials are only read by CodeIgniter's Email configuration and are never included in notification bodies or application log messages. Do not enable email delivery until a non-production SMTP test is explicitly approved.

Emails include a status, reservation reference, schedule or total when relevant, and a TechReserve reservation link. Both HTML and plain-text alternatives are generated. They do not include passwords, tokens, API keys, payment account numbers, or unnecessary personal details.

## Outbox worker

After SMTP is configured and delivery is explicitly approved, run from the TechReserve project directory:

```powershell
php spark notifications:send-emails
```

The command handles up to 25 messages per run. It claims rows with a lease, marks successful delivery, retries temporary failures with exponential backoff (60 seconds up to one hour), and stops after five attempts. Invalid SMTP configuration and SMTP 5xx rejections are marked failed without retry. A scheduled invocation can process later retries; no worker is started automatically.

The per-user event key and database uniqueness constraint prevent duplicate queue entries. SMTP delivery is at-least-once: if the provider accepts a message but the worker cannot record that acceptance, a later retry can send a duplicate.

## Current limitation

The MariaDB instance has prior Aria privilege-table corruption and InnoDB crash evidence. The migration and notification database operations remain disabled by default; no database integration tests or email delivery have been run. Verify database health, obtain a restorable backup, provision isolated test data, apply the migration only after approval, then test in-app and mocked SMTP delivery before enabling production SMTP.
