# Phase 3: Resource management

## Local setup and account provisioning

The additive `2026-11-17-000002_AddResourceDetails` migration adds nullable
`location VARCHAR(150)`, unsigned `capacity INT`, and `deleted_at DATETIME`.
Capacity must be positive when supplied through the application. Existing
resource records and all completed migrations are preserved. Other checkouts
can apply the migration using `php spark migrate`; do not reset migrations.

From an interactive terminal in the TechReserve directory:

```powershell
php spark admin:create
```

The command works only in development mode through PHP CLI. It accepts no
arguments/options or redirected input. Enter an `OFFICE-` identifier (uppercase
letters, digits, and hyphens; max 30 characters), name, office email, password,
and confirmation. Passwords are entered through hidden prompts and must match,
contain at least 12 characters, and occupy at most 72 bytes. On Windows the
hidden prompt uses PowerShell's secure console input; on Unix terminal echo is
temporarily disabled and restored. A failed hidden prompt creates no account.
Passwords are never arguments or echoed command output.

Duplicates are rejected by identifier/email checks and the existing unique
indexes. Existing borrowers are never promoted or overwritten. Provisioning
does not use Student Records, and academic information is left null. The new
account is an active administrator with a PHP password hash. No administrator
account/password is seeded or stored in this repository. Use the office login
identifier in the existing login form's Student ID field. There is no public
administrator-registration route. Keep this trusted-terminal command local.

## Browser URLs

- Login: http://127.0.0.1:8080/login
- Administrator dashboard: http://127.0.0.1:8080/admin/dashboard
- Administrator resource list: http://127.0.0.1:8080/admin/resources
- Create resource: http://127.0.0.1:8080/admin/resources/new
- Borrower catalog: http://127.0.0.1:8080/borrower/resources
- Details: append `/{id}` to the appropriate resource list URL.
- Edit/archive confirmation: append `/{id}/edit` or `/{id}/archive` to the
  administrator list URL. Follow the links rather than guessing IDs.

Login first with the corresponding role. Dashboard links open resource pages.
The original login, registration, logout, sessions, and home page are retained.
All new state changes are CSRF-protected POSTs; route filters check the current
account's role and active status. Borrowers cannot call administrator actions,
including direct POST requests. Resource responses use no-store and skip debug
toolbar collection. Output is escaped; listing queries use Query Builder.

## Resource rules

- Facilities represent one independently reservable space, so quantity is 1.
- Equipment quantity represents positive interchangeable stock, not attendees.
- Optional facility capacity is a positive number of people. The capacity field
  is hidden, disabled, and cleared when equipment is selected. The server ignores
  any supplied equipment capacity and stores NULL, including when converting a
  facility to equipment. Quantity/capacity are limited to unsigned INT range.
- Hourly rates are nonnegative decimals up to `99999999.99`, with at most two
  decimal places. Rates are normalized as decimal strings without float math.
  The module does not introduce a currency or billing-rounding policy.
- Location is optional (max 150 characters); description is optional (max 5000
  characters). Names are required (max 150 characters).
- Operational status is available, unavailable, or maintenance. All three are
  visible in the borrower catalog. This is not time-slot availability.
- Lists support name/description/location search, type and status filters,
  and pagination at ten resources per page.

Archiving requires an explicit confirmation checkbox and sets `deleted_at`
through CodeIgniter soft deletion. No permanent-deletion endpoint exists.
Archived resources are excluded from both lists and from detail/edit/archive
routes, including direct requests. Their records and reservation references
remain intact. Restore/archive-history interfaces are outside this phase.

The existing `reservation_items` table can later reference resource IDs and
store rate snapshots. No reservation, fee calculation, payment, or notification
features are implemented here. Future code must validate schedules, operational
status and quantities independently of the catalog display.

## Automated verification

```powershell
$env:TECHRESERVE_RESOURCE_INTEGRATION = '1'
$env:TECHRESERVE_AUTH_INTEGRATION = '1'
php vendor/bin/phpunit tests/integration tests/unit/HealthTest.php tests/session/ExampleSessionTest.php --no-coverage --do-not-cache-result --no-logging --testdox
Remove-Item Env:\TECHRESERVE_RESOURCE_INTEGRATION
Remove-Item Env:\TECHRESERVE_AUTH_INTEGRATION
```

The resource suite creates randomly prefixed `p3_...` tables using the existing
migrations, uses synthetic administrator/borrower/resource records, and starts
one isolated PHP server on a random loopback port. Its Student Records URL
points to a closed port: resource operations do not depend on that service.
All database writes/deletions are confined to those temporary tables. Real
application tables and existing Student Records data remain untouched.
Teardown stops only the owned test process and drops only the test's tables.
Temporary runtime files remain in the operating system's temp directory.

Coverage includes create/view/edit, validation, malicious field overrides,
guest/borrower/inactive-account access, CSRF, confirmed soft deletion, retained
reservation foreign-key references, escaped descriptions, both catalogs'
filters/pagination, administrator creation/duplicate prevention, and CLI
production/noninteractive refusal. Provisioning service tests use an explicitly
injected isolated UserModel. An additional interactive Windows terminal check
confirmed masked password/confirmation prompts and successful creation in a
temporary isolated users table, which was then removed. Visual/mobile browser
flows still require manual acceptance testing.

The Phase 2 suite is rerun unchanged. SQLite starter database examples remain
unavailable in the current PHP build; these opt-in suites use MySQL instead.
