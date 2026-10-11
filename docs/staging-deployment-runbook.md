# Controlled staging deployment runbook

This runbook is for a **separate, non-production cPanel staging site**. Passing CI and validating the ZIP are necessary, but do not prove the app is ready for production or for handling real funds.

## 1. Release identity and guardrails

- Candidate branch: `ci/mysql8-wallet-concurrency-gate`
- Candidate commit at runbook creation: `6c50562234a23dac6e83a8d07c5d3a98e76dd682` (recheck the PR head before using an artifact; documentation fixes create a newer commit).
- Pull request: https://github.com/Semizzy/thrift-collector-platform/pull/1
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
2. Download the `thrift-collector-deployment` artifact from the matching workflow run. It is a wrapper archive containing `thrift-collector-deployment.zip`; extract that inner ZIP.
3. Verify the ZIP's integrity before upload. Do not use an artifact from an earlier commit.
4. Upload and extract the inner ZIP into the private staging application directory. Keep the previous staging artifact available for rollback.
5. Verify `artisan`, `composer.lock`, `vendor/autoload.php`, migrations, `public/index.php`, and `public/app/index.html` exist.

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

1. Confirm the configured database host/name/user carefully before running any migration.
2. Take a backup of the **staging** database (even if empty), then run `php artisan migrate --force` against staging only. Never run `migrate:fresh` on any shared or production database.
3. Run `php artisan optimize:clear`, then verify the application boots and routes register. Only enable config/route caches after the environment is correct.
4. Check `/up`, `/api/v1/health`, the web app shell, and the static assets under `/app/`.
5. Test with synthetic accounts only: registration, email verification, login/logout, password reset, transaction-PIN setup/lockout, role and tenant authorization, group creation, contribution recording/confirmation, reconciliation review, duplicate references, withdrawal rejection/refund idempotency, and audit events.
6. Confirm unauthenticated users cannot reach protected endpoints, one business cannot read another business's records, ordinary members cannot call admin endpoints, and a payout request does not initiate a bank transfer.
7. Review Laravel logs, browser console/network requests, HTTP status codes, mail delivery, and cPanel error logs. Record failures and fix them on the PR branch before repeating staging validation.

## 6. Controlled scheduler and recovery checks

After the basic staging smoke tests pass, add a staging-only cPanel Cron Job using the hosting provider's actual PHP CLI path:

```cron
* * * * * /absolute/path/to/php /absolute/path/to/staging/artisan schedule:run >> /absolute/path/to/staging/storage/logs/scheduler.log 2>&1
```

Verify the path and permissions first. Confirm scheduled work runs only against staging data. Do not enable any live-payment action. Keep backups outside the public root.

## 7. Evidence required before considering production

Record the candidate SHA, workflow URL, artifact ID, PHP/database versions, migration output, smoke-test results, log review, backup/restore rehearsal, and unresolved issues. Production remains blocked until financial/security/legal release gates in `cpanel-deployment.md` are separately reviewed and explicitly approved. A successful staging install is not permission to merge, deploy to production, import customer data, or enable money movement.
