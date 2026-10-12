# Controlled staging deployment runbook

This runbook is for a **separate, non-production cPanel staging site**. Passing CI and validating the ZIP are necessary, but do not prove the app is ready for production or for handling real funds.

## 1. Release identity and guardrails

- Candidate branch: `feat/production-readiness-gate`
- Candidate commit: use the exact current head SHA shown on PR #8 and verify that every required CI job passed for that SHA immediately before selecting an artifact. Documentation changes create a newer commit and artifact; never use an artifact from an earlier run.
- Pull request: https://github.com/Semizzy/thrift-collector-platform/pull/8
- Keep the PR in draft until the code review, staging smoke tests, and required CI checks are complete.
- Do not merge or deploy to production as part of this runbook.
- Do not enter real customer records, real payment credentials, or real payout destinations. Live provider execution must remain disabled.

## 2. Create isolated staging resources in cPanel

1. Create a staging subdomain (for example, `staging.example.com`) and enable HTTPS. Do not point it at the production document root.
2. Create a **new, empty staging database** and a dedicated database user with privileges only on that database. Never reuse production database credentials or a production database.
3. Create a private application directory outside the public web root. The subdomain document root should point to the release's `public/` directory. If the host cannot do that, follow the private-app/public-files layout in `cpanel-deployment.md`; never expose the whole project, `.env`, `vendor/`, `storage/`, logs, or ZIP backups under `public_html`.
4. Confirm the host's PHP CLI version is 8.2+ and that PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, Fileinfo, and other required extensions are enabled. Record PHP, MySQL/MariaDB, and Composer versions.
5. Ensure staging email is sandboxed or routed to a test inbox. Disable scheduled tasks until the app has passed the initial smoke tests.

## 3. Select and upload the exact candidate

1. Open the PR and verify the latest head SHA and that all required CI jobs passed on that exact SHA.
2. Download the `semizzy-thrift-deployment` artifact from the matching workflow run. It is a wrapper archive containing `semizzy-thrift-deployment.zip`; extract that inner ZIP.
3. Verify the outer and inner ZIPs with `unzip -t` (or an equivalent archive integrity check). Do not use an artifact from an earlier commit.
4. The inner ZIP contains the top-level directory `deployment-package/`. Extract that directory's contents into the private staging application directory; do not create an accidental nested `deployment-package/deployment-package/` layout. Keep the previous staging artifact available for rollback.
5. Verify `artisan`, `composer.lock`, `vendor/autoload.php`, migrations, `public/index.php`, and `public/app/index.html` exist in the extracted app.

## 4. Configure staging only

Create a new `.env` in the private application directory; do not publish it or commit it. Set at minimum:

- `APP_ENV=staging` (or the deployment's approved non-production environment), `APP_DEBUG=false`, `APP_URL=https://<staging-host>`.
- A newly generated, staging-only `APP_KEY`; back it up securely and never copy the production key into staging.
- The dedicated staging database name, username, and password.
- Secure HTTPS/session settings and a test-only mail configuration.
- Cache, session, and queue drivers supported by the host; do not assume a queue worker exists.
- Provider/payment credentials omitted or sandbox-only. Keep provider execution disabled and do not enable live transfers.

Use the host's confirmed PHP CLI and Composer paths. Install dependencies from the committed lock file if the artifact does not already include production `vendor/`; do not run `composer update` on the host.

## 5. Database and application checks

1. Confirm the configured database host/name/user carefully before running any migration; record the target database name without exposing its password.
2. Before migration, create a timestamped SQL dump of the **staging database** (even if empty) and a timestamped archive of the current staging application files, including the current `.env` only in a separately encrypted/private backup if it must be preserved. Keep both backups outside the public web root with restrictive permissions. Record file sizes and SHA-256 checksums; confirm each archive/dump is readable. Do not include secrets in CI artifacts or public folders.
3. Then run `php artisan migrate --force` against staging only. Never run `migrate:fresh` on any shared or production database.
3. Run `php artisan optimize:clear`, then verify the application boots and routes register. Only enable config/route caches after the environment is correct.
4. Check `/up`, `/api/v1/health`, the web app shell, and the static assets under `/app/`.
5. Test with synthetic accounts only: registration, email verification, login/logout, password reset, transaction-PIN setup/lockout, role and tenant authorization, group creation, contribution recording/confirmation, reconciliation review, duplicate references, withdrawal rejection/refund idempotency, and audit events.
6. Confirm unauthenticated users cannot reach protected endpoints, one business cannot read another business's records, ordinary members cannot call admin endpoints, and a payout request does not initiate a bank transfer.
7. Review Laravel logs, browser console/network requests, HTTP status codes, mail delivery, and cPanel error logs. Record failures and fix them on the PR branch before repeating staging validation.

## 6. Controlled scheduler and recovery checks

Keep scheduled tasks disabled until the smoke tests pass. After basic staging validation, first rehearse recovery in a **separate isolated restore directory and disposable restore database** that are not used by the active staging app:

1. Restore the pre-migration staging SQL dump into the disposable restore database, not the active database. Verify table counts, key business records, migration table state, and referential integrity against the recorded pre-migration baseline.
2. Extract the pre-migration file backup into a separate restore directory outside the web root. Verify the expected Laravel files and compare the recorded checksum manifest. Never overwrite the active staging tree as part of the first restore test.
3. Record restore start/end time, commands, exit codes, checksums, database/table verification results, and any errors. Remove the disposable restore database and temporary secrets securely after the rehearsal.
4. Rehearse rollback by switching the staging release directory/document root back to the preserved prior staging release, following the host's actual cPanel layout. If a migration is not safely reversible, restore the matching pre-migration staging backup only into the isolated test target and document the recovery procedure; do not casually restore over active data.
5. Only after the isolated restore and rollback checks pass, consider a staging-only cron test using the hosting provider's actual PHP CLI path:

```cron
* * * * * /absolute/path/to/php /absolute/path/to/staging/artisan schedule:run >> /absolute/path/to/staging/storage/logs/scheduler.log 2>&1
```

Verify the path and permissions first. Confirm scheduled work runs only against staging data. Do not enable any live-payment action. Keep backups outside the public root.

## 7. Evidence required before considering production

Record the candidate SHA, workflow URL, artifact ID, PHP/database versions, migration output, smoke-test results, log review, backup/restore rehearsal, and unresolved issues. Production remains blocked until financial/security/legal release gates in `cpanel-deployment.md` are separately reviewed and explicitly approved. A successful staging install is not permission to merge, deploy to production, import customer data, or enable money movement.
