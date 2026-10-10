# cPanel deployment and release checklist

This project targets standard PHP cPanel hosting first. This guide is a deployment procedure, not a claim that the current foundation is production-ready. Do not import real customer records or enable real-money processing until the release gates below are met.

## Hosting requirements to confirm with the provider

- PHP 8.2 or newer, matching the project's Composer constraints and Laravel 12 requirements.
- Composer 2 available in cPanel Terminal/SSH, or a locally built deployment artifact produced from the committed lock file.
- Required PHP extensions for the selected Laravel version and database driver, including PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, Fileinfo, and cURL as needed by installed packages.
- MySQL or MariaDB version supported by the selected Laravel version; verify the actual version and collation before production.
- HTTPS certificate, writable storage/cache directories, and cPanel Cron Jobs access.
- Enough disk, database, inode, process, and execution-time limits for the application and scheduled tasks.

Do not assume a hosting plan meets these requirements merely because it advertises PHP hosting. Record the confirmed versions and limits before deployment.

## Recommended directory layout

Keep the application source outside the public web root where the hosting account permits it. Point the domain's document root to the Laravel `public/` directory. If the plan only permits `public_html` as the document root, keep the Laravel application files outside `public_html` and place only the contents of Laravel's `public/` directory in `public_html`, adjusting the front controller's bootstrap path carefully and preserving the original `public/index.php` logic.

Never expose `.env`, `vendor/`, `storage/`, database dumps, logs, source-control metadata, or backup archives over HTTP. Do not solve document-root restrictions by copying the entire application into a public directory.

## Initial deployment sequence

1. Create a new database and a least-privileged database user in cPanel. Grant access only to that application's database.
2. Upload the reviewed release artifact or clone the intended repository revision into a private application directory. Deploy a tagged/recorded commit, not an unreviewed working tree.
3. Install production Composer dependencies from the lock file: `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`.
4. Create the production `.env` outside the public web root. Set `APP_ENV=production`, `APP_DEBUG=false`, the exact HTTPS `APP_URL`, the MySQL connection details, secure mail settings, and production cache/session/queue drivers supported by the host.
5. Generate `APP_KEY` once for a new installation. Back it up securely. Do not regenerate it during routine deployment because encrypted data and sessions may become unreadable.
6. Confirm HTTPS works before accepting credentials. Ensure redirects, secure cookies, and trusted-proxy configuration are correct for the hosting provider.
7. Run database migrations only after taking a backup and reviewing the migration plan. Do not use `migrate:fresh` on production.
8. Run Laravel's supported configuration/route/view cache commands only after verifying the environment values and application boots correctly. Clear stale caches when rolling back or changing configuration.
9. Set the domain document root to `public/`, verify storage links only if the app actually needs them, and test that private paths return 403/404.
10. Smoke-test `/up`, `/api/v1/health`, country configuration, registration, email verification, login/logout, password reset, authorization boundaries, and database writes using non-production test accounts.
11. Set a cPanel Cron Job to run Laravel's scheduler once per minute using the hosting provider's confirmed PHP CLI path, for example: `* * * * * /usr/local/bin/php /home/ACCOUNT/app/artisan schedule:run >> /dev/null 2>&1`. Replace the example path with the actual path from the host; never copy it blindly.
12. Verify mail delivery, cron execution, log rotation, backup creation, and a restore rehearsal. Do not expose verbose exceptions to visitors.

## Queues and scheduled jobs

Prefer the database queue driver for the first shared-hosting release. If asynchronous work is introduced, confirm whether the host permits a reliable scheduled queue runner. Use bounded jobs, retries with backoff, idempotency keys, and explicit failure handling. Do not depend on Redis, RabbitMQ, Supervisor, systemd, Docker, root access, or a permanently running worker on ordinary shared hosting.

Cron is not a substitute for idempotency. A job may run late or more than once; financial or notification jobs must safely tolerate duplicate execution.

## Backup and recovery

- Schedule database backups and application-file backups separately.
- Keep backup files outside the public web root and restrict account access.
- Encrypt backups before sending them off-host; do not commit secrets or dumps to Git.
- Define retention and recovery-point/recovery-time targets before launch.
- Test restoring into a separate database and directory. A successful backup command alone is not proof of recoverability.
- Back up `.env` and `APP_KEY` through an approved secure process, never through a public download link.

## Release gates before any real-money capability

All items below are required; this checklist alone does not satisfy them:

- Automated tests prove tenant isolation, role/permission enforcement, idempotency, concurrency handling, immutable posted financial history, and balanced double-entry journals.
- Cash records, payment authorization, provider settlement, payout execution, and reconciliation remain distinct states.
- Provider webhooks have signature verification, replay/duplicate protection, and auditable handling of out-of-order events.
- Maker-checker controls prevent self-approval and protect high-risk corrections.
- Country, currency, provider, and transaction limits are explicit and enforced server-side.
- Legal/compliance review approves the actual operating model in each launch country and confirms whether licensed partners or regulatory approvals are required.
- Security review, backup restoration rehearsal, monitoring, incident response, and a limited pilot are complete.
- A documented release decision explicitly enables each approved capability. Until then, real payment execution, custody, lending, investment, and cross-border money movement must remain disabled.

## Rollback

Keep the previous known-good release artifact and a database backup. If a release fails, put the app into maintenance mode if available, restore the previous compatible code, clear stale caches, and follow the tested database rollback/recovery plan. Do not assume that reversing code reverses data migrations safely.
