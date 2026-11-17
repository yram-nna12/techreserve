# Phase 4: reservations and administrator approval

## Local browser workflow

- Login: http://127.0.0.1:8080/login
- Borrower history: http://127.0.0.1:8080/borrower/reservations
- Borrower creation/preview: http://127.0.0.1:8080/borrower/reservations/new
- Administrator queue: http://127.0.0.1:8080/admin/reservations
- Details: follow a reservation link in the appropriate list.

Use existing student and separately provisioned office accounts. The dashboard
and catalog provide navigation. All interfaces are local; nothing is deployed.

Select one or more operational resources, equipment quantities, purpose, and
one shared start/end interval. Matching equipment is shown as one catalog
choice; the server allocates the requested quantity across its individual
inventory records. Preview availability and estimated fees, then submit for
administrator review. Preview does not reserve stock or store a reservation.
Submission rechecks current rates and availability and requires a valid
teacher-signed PDF, JPG, or PNG request form. The file is MIME-checked, size
limited (10 MiB by default; configurable up to 100 MiB), stored privately under
`writable/private/reservation-forms` with a random name, and linked to both the
reservation and submitting borrower. Set
`reservationDocuments.maxFileSizeBytes` in the local `.env` to override the
default (valid range: 1 KiB–100 MiB).

The latest form remains pending until an administrator manually reviews it;
the application does not attempt to authenticate handwritten signatures.
Administrators can securely download, accept, or reject the latest form.
Rejection requires a reason. A borrower can upload a first form for a legacy
pending reservation without one, or replace a rejected form; each new version
returns to pending review. Borrowers can see/cancel only their own pending
requests.

Administrators filter the queue by status and Manila schedule dates, view the
borrower's identity/academic information, and approve or reject pending requests.
Rejection and administrator cancellation require remarks. Approved requests
can be cancelled by administrators only before their start, and completed only
at/after their end. Repeated/invalid decisions are rejected. The existing schema
stores the latest reviewing administrator, remarks, and UTC review timestamp;
this phase does not add an append-only decision-event history.

## Centralized policy

`app/Config/Booking.php` defines:

- Minimum 10 minutes, 10-minute clock increments, maximum 480 minutes.
- Maximum starting time 30 days ahead (the end may extend past that boundary).
- Asia/Manila input/display and PHP currency.
- Up to 20 resources per request and at most three transaction attempts.

Both clock times must have minutes 00, 10, 20, 30, 40, or 50, with no seconds.
Separate end-date input supports midnight crossings within the duration limit.
Invalid calendar dates, past starts, nonpositive durations, duplicate resources,
out-of-range/fractional quantities, and supplied ownership/status/price fields
are rejected on the server. Purpose is required and limited to 2000 characters.
Facilities always request one unit. The application-wide timezone is unchanged.

## Money and availability

All reservation datetimes are explicit UTC strings in MySQL DATETIME columns.
Display and filter inputs convert explicitly through Asia/Manila, independent
of MySQL's session timezone. Comparisons use UTC consistently.

Only approved reservations consume availability during their half-open interval
`[start, end)`. Back-to-back intervals are allowed. Pending submissions receive
an initial check but do not hold inventory. Approval performs an authoritative
check; a pending request can therefore become unavailable before approval.

Equipment uses peak simultaneous allocations, clipping existing approved
intervals to the candidate interval and processing departures before arrivals
at equal times. Summing all overlapping bookings would incorrectly reject some
valid requests and is not used.

Each subtotal is `hourly_rate * minutes / 60 * quantity`, rounded half-up to
integer cents. Totals sum the individually rounded lines. Bounds are checked
before multiplication/summation to prevent integer/DECIMAL(10,2) overflow.
Reservation items retain hourly-rate and subtotal snapshots; later resource-rate
changes do not reprice submitted requests. Names/types are current resource
labels; the approved migration does not add historical label snapshots.

## Transactions and resource coordination

The transaction wrapper uses transaction-local READ COMMITTED and explicit
current/locking reads. It locks resource rows in ascending numeric ID order,
then checks actors and reservation state inside the transaction. Submission,
approval, cancellation, completion, and reservation-sensitive resource changes
use these same resource mutexes. Locks include matching equipment catalog
records and facility conflict groups. Reservation header, items, and document
metadata commit together or roll back. MySQL deadlocks/lock timeouts receive
bounded retries; other errors do not.

Submission forms carry a UUID v4 idempotency key. A repeated identical request
from the same borrower returns the original reservation; different payloads or
owners cannot reuse the key. A unique index provides a final database guard.
Retries preserve the stored original price rather than generating another row.

Upcoming/active approved commitments prevent resource archiving, type changes,
or changing operational status away from available. Equipment stock cannot be
reduced below its peak approved commitments. Rate/name/location edits remain
possible. Staff must explicitly cancel affected approved reservations first;
there is no silent cancellation. No historical reservation is deleted.

## Migration

`2026-11-17-000003_AddReservationBookingFields` adds:

- `reservations.currency VARCHAR(3) DEFAULT 'PHP'` (new submissions explicitly store PHP).
- Nullable unique `reservations.submission_key CHAR(36)`.
- Unique `(reservation_id, resource_id)` on reservation items.
- A `(status, start_datetime, end_datetime)` reservation index.

Existing resource/reservation foreign-key indexes are retained rather than
duplicated. Before modifying schema, migration preflight refuses duplicate
legacy resource lines; it never merges or deletes them. No completed migration
is edited.

Step 19 adds two further additive migrations:

- `2026-11-17-235100_AddResourceCatalogAndConflicts` adds category, catalog
  grouping, optional unique inventory identifiers, and facility conflict groups.
- `2026-11-17-235200_CreateReservationDocuments` creates private document
  metadata/history and its reservation, borrower, and reviewer foreign keys.

Both migrations have been created but **not run**. Apply only after separate
database approval; do not reset/recreate databases.

## Tests

```powershell
$env:TECHRESERVE_AUTH_INTEGRATION = '1'
$env:TECHRESERVE_RESOURCE_INTEGRATION = '1'
$env:TECHRESERVE_RESERVATION_INTEGRATION = '1'
php vendor/bin/phpunit tests/integration tests/unit tests/session/ExampleSessionTest.php --no-coverage --do-not-cache-result --no-logging --testdox
node --test tests/js/resource-form.test.cjs
Remove-Item Env:\TECHRESERVE_AUTH_INTEGRATION
Remove-Item Env:\TECHRESERVE_RESOURCE_INTEGRATION
Remove-Item Env:\TECHRESERVE_RESERVATION_INTEGRATION
```

The reservation suite uses synthetic rows in random `p4_...` tables, a guarded
HTTP test server on a random loopback port, and separate PHP worker processes
for genuine competing MySQL approvals/submissions. A parent-held resource lock
releases the workers together. It does not use the single-threaded HTTP server
to claim concurrent execution. Worker credentials travel only in process
environment, never source files, arguments, or test output. A scoped temporary
trigger forces a second-item insertion failure to verify complete rollback.
The deadlock retry branch is tested with injected retryable errors.

Only test-owned tables/processes are cleaned up. The standard SQLite database
examples remain outside this command because this PHP build lacks SQLite.
Manual browser acceptance is separate from automated HTTP checks.

Manual acceptance: submit a multi-resource request; inspect fees/history;
approve it from an office session; try an overlapping facility request and a
back-to-back request; check equipment stock across partially overlapping times;
test rejection, pending cancellation, approved cancellation, ownership isolation,
and resource-edit safeguards. Completion requires a reservation whose end has
passed. Do not change real historical schedules merely to test completion.

Verified implementation run: 39 PHP tests / 922 assertions passed, covering
Phase 2/3 regressions and Phase 4; both JavaScript resource-form tests passed.
The new migration was applied successfully. Normal tables remained at two
users, two resources, zero reservations and zero items. Isolated test tables
were removed, including two orphan test-table metadata entries left when MySQL
stopped during an earlier setup attempt. No application records were deleted.

At the final environment check MySQL was listening on 3306, while Oracle's
listener still occupied 8080 and Student Records was not listening on 8081.
The suite used random test ports. For the normal browser URLs, first free 8080
through the usual approved local service procedure and run the existing
development launcher. This implementation did not stop Oracle or change ports.

## Approval and payment boundary

An administrator can approve a reservation only after accepting its latest
signed form and rechecking availability. Payment initiation and resuming an
existing payment attempt both independently require an approved reservation
and an accepted latest form. Form review and payment-state updates do not make
external calls inside their database transactions. A paid reservation still
cannot be borrower-cancelled because refunds are not implemented.
