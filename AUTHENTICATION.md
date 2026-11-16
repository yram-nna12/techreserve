# Phase 2: Student accounts

Open these URLs while TechReserve and Student Records are running:

- Registration: http://127.0.0.1:8080/register
- Login: http://127.0.0.1:8080/login
- Borrower dashboard: http://127.0.0.1:8080/borrower/dashboard
- Office landing page: http://127.0.0.1:8080/admin/dashboard (existing administrators only)

Use `127.0.0.1` consistently. TechReserve's non-secret `app.baseURL` setting now
uses that host so links and redirects preserve the browser's session cookie.
CodeIgniter may include `index.php` in generated links; these URLs also work.
The original home page is preserved. Logout is a CSRF-protected POST through
the dashboard's Log out button, not a public GET link.

## Data and registration

The new `2026-11-17-000001_AddAcademicFieldsToUsers` migration adds nullable
program, program_code, specialization, and department columns. Existing
migrations are unchanged. Run `php spark migrate` on other installations.
Never reset migrations to install this feature.

Registration accepts only student ID, password, and confirmation. Passwords
must match, contain at least 12 characters, and occupy at most 72 bytes (to
avoid bcrypt truncation). Student IDs permit letters, digits, and hyphens.
The existing Student Records service must confirm enrollment and return a
matching student ID plus valid identity details. Only that response supplies
the name, institutional email, and academic fields. This is a snapshot at
registration, not a shared database or continuous enrollment synchronization.

Every public registration creates an active borrower. Posted roles, statuses,
and identity fields are ignored. Duplicate IDs and emails are checked before
insertion and protected by the existing database unique indexes. API failures,
timeouts, invalid identities, and rejected verification create no account.
Passwords use PHP password_hash/password_verify; plain passwords are never
retained with form flash data or authentication session data.

## Sessions and roles

TechReserve uses its own `techreserve_session` cookie to avoid collisions with
other apps on the same host. Sessions keep only a user ID for authentication;
the role and active status are checked against TechReserve's users table on
every protected request. Login destroys the old session ID and rotates CSRF;
logout destroys the authenticated session. A borrower cannot open the office
dashboard and an administrator cannot open the borrower dashboard.

Session cookies are HTTP-only and SameSite=Lax. Secure cookies require HTTPS
when deployed; local development uses the requested HTTP loopback URLs.
All new forms use session-based CSRF protection. Auth responses use no-store;
the debug toolbar skips auth pages so form/session values and password-hash
queries are not persisted in toolbar captures. Exception traces mask password
fields, and registration's password arguments use PHP SensitiveParameter.

Facilities Office accounts must be provisioned separately by trusted staff.
There is no public administrator-registration route or administrator seeder.
The shared login currently uses student_id for both roles; an existing admin
must have a non-null unique login identifier in that column. This phase adds
only a restricted office landing page, not administrator management.

## Basic rate limiting

The existing file-cache-backed CodeIgniter throttler applies token buckets:

- Login: capacity 5 attempts per student ID, replenishing over 300 seconds;
  plus capacity 30 attempts per IP, replenishing over 300 seconds.
- Registration: capacity 10 attempts per IP, replenishing over 300 seconds.

The limit includes successful attempts and returns HTTP 429 with Retry-After.
These are basic development limits, not a distributed or atomic lockout system.
Keep the cache writable; use a suitable shared/atomic strategy if deploying
multiple workers. Login does not depend on Student Records being available.

## Repeatable tests

From the TechReserve directory in PowerShell:

```powershell
$env:TECHRESERVE_AUTH_INTEGRATION = '1'
php vendor/bin/phpunit tests/integration/AuthenticationTest.php --no-coverage --do-not-cache-result --no-logging --testdox
Remove-Item Env:\TECHRESERVE_AUTH_INTEGRATION
php vendor/bin/phpunit tests/unit/HealthTest.php --no-coverage --do-not-cache-result --no-logging
```

The opt-in suite uses the configured MySQL credentials internally, creates a
randomly prefixed users table, and starts two temporary PHP servers on random
loopback ports. The API server returns synthetic fixtures through the same
HTTP client and API-key header as the real integration. It simulates enrollment
rejection, missing records, HTTP 503, timeout, and mismatched identity. Existing
Student Records rows are never modified. This suite does not claim to test the
real Student Records database/controller; Integration Point 1 covers that.

The app test router refuses requests without an explicit isolated table prefix.
Windows PHP normalizes dotted environment names, so the test router explicitly
preserves inherited test configuration before CodeIgniter loads .env.
The suite stops only servers it launched and removes only its own randomly
prefixed table. Temporary session/cache/log files remain in the operating
system's temporary directory for diagnosis. Real users remain untouched.

The starter database example tests need SQLite, which this PHP build does not
provide. The starter unit and session checks can run separately; the opt-in
HTTP suite exercises real session cookies, session regeneration, and logout.

Manual registration creates a real TechReserve account for the enrolled ID you
enter. Use an approved synthetic student fixture for a manual registration;
do not use another student's identity. No accounts or passwords are seeded by
this implementation.
