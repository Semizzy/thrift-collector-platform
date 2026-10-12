# Production Readiness Runbook — cPanel Staging

**Status:** preparation checklist only. This document is not evidence that a restore drill, production smoke test, or real-money release has occurred.

## Non-negotiable launch mode

Until the production-readiness issue is explicitly signed off, run this application as a controlled non-financial beta. Do not accept, hold, promise, or transfer real customer funds through this foundation. Keep all payment provider execution disabled. An approval status, uploaded receipt, provider API acceptance, webhook delivery, or reconciliation match is not proof of settlement and must not credit a wallet by itself.

## 1. Ledger and contribution-adjustment design gate

Reversal approval on this draft branch posts a balanced inverse journal inside the decision transaction; the original journal remains immutable. Corrections remain decision records unless a separately reviewed correction-posting implementation is added. Never overwrite posted journal entries or rewrite financial history; a contribution record may be marked reversed only after its inverse journal commits.

Required posting contract before implementation is enabled:

- Use integer minor units and one ISO-style three-letter currency per journal. Never convert or aggregate balances across currencies implicitly.
- Post a balanced journal atomically: total debits equal total credits, with at least two lines, all amounts positive, and all accounts scoped to the same business and currency.
- Use a unique, deterministic idempotency key per adjustment decision and posting version. Replays with identical details return the original transaction; reuse with different details fails closed.
- Lock the contribution/adjustment and affected accounts in a stable order inside one database transaction. Re-check pending/approved state under lock. A requester cannot decide their own request; a decision cannot be posted twice.
- A reversal must be a new journal that exactly offsets the original posted journal, references its source transaction, and cannot exceed the unreversed amount. A correction must be an explicit delta/reversal-and-replacement journal; it must not overwrite history.
- Approval and posting are separate states. A posting failure leaves the decision visible and retryable, but must never leave a partially posted journal. Do not mark an adjustment financially applied until the journal commits.
- Add database-enforced uniqueness, foreign keys, positive-amount and currency constraints where supported. The application-level balanced-journal check is not a substitute for schema constraints or tests.
- Add tests for same-actor rejection, double approval, repeated jobs, conflicting idempotency payloads, over-reversal, cross-business IDs, currency mismatch, rollback after injected failure, and concurrent duplicate posting.

**Current implementation on this draft branch:** approving a reversal invokes `WalletLedgerService::reverseContribution()` inside the decision transaction. It locks the confirmed contribution and original journal, validates balanced original entries and currency, posts a separate inverse journal with the stable key `contribution:{id}:wallet-reversal`, preserves the original journal, and marks the contribution reversed only after a successful posting. Independent-connection MySQL race coverage and injected journal-entry-write failure/transaction-rollback coverage are present; the complete MySQL 8 PHP test suite passed in CI run [#85](https://github.com/Semizzy/thrift-collector-platform/actions/runs/38244418883) on commit `78e00056d3b5c5646a2d3334217b0a5eeda743c1`. Remaining cases still requiring explicit coverage include conflicting adjustment IDs for an existing reversal, corrupted/missing wallet-line scenarios, and broader cross-business/account/currency mismatch cases. Keep the release gate incomplete until these remaining cases and staging/recovery evidence are reviewed.

## 2. Double-entry, precision and concurrency gates

- Money is represented as integer minor units; no binary floating-point arithmetic for amounts or balances.
- Every journal is balanced by currency and business. Account-normal-balance rules and balance-after snapshots must be verified against journal history.
- Idempotency keys are protected by database unique indexes, not only application-level first-or-create checks.
- Lock all affected accounts in deterministic key order; test parallel debits, credits, refunds and duplicate settlement on MySQL 8.
- Enforce source/reference uniqueness and immutable posting history at database level where feasible. Corrections are compensating entries, never silent edits/deletes.
- Reconciliation tests compare provider/statement evidence with journal transactions, not merely a mutable status field.

## 3. Provider settlement safety gates

For each provider, separately implement and test:

1. Verify webhook signature against the raw request body and configured secret using constant-time comparison; reject missing/invalid signatures. Do not trust client-supplied status, amount, currency, business, or user fields.
2. Persist a provider event ID and payload hash with a unique constraint. Duplicate events must be idempotent; conflicting replays are quarantined and audited.
3. Verify provider reference, expected amount, currency, business, recipient/account, and a server-created pending operation before settlement.
4. Query the provider's authenticated status API before posting settlement when the event is ambiguous or retried. Treat timeouts and accepted/pending responses as unknown—not success.
5. Record an immutable settlement decision and post the journal in one local database transaction. A database failure must leave the event retryable without double credit.
6. Reconcile settlement against provider records and bank statements. Quarantine unmatched, partial, conflicting, reversed, or duplicate items for independent review.
7. Use bounded retries with backoff and an operator-visible dead-letter/quarantine state. Never blindly retry a transfer that might already have executed.

Provider-specific sandbox evidence, signed webhook tests, status requery and reconciliation are required before a provider can be enabled. Credentials stored in configuration do not enable execution.

## 4. Authorization, maker-checker and audit gates

- Every business money route must require authenticated, verified access and an active membership in the exact business; cross-business IDs must return no data and cause no mutation.
- Enforce role permissions on the server, not just navigation/UI. Platform administrators have platform-wide access to admin modules and business-scoped modules without being forced into ordinary business memberships or per-module allowlists. Privileged writes through business-scoped routes are recorded in the business audit trail; each target financial/security workflow must also record its own decision and outcome. Maker-checker and self-approval restrictions remain mandatory.
- The requester must not approve their own deposit evidence, payout, adjustment, refund, or privileged account change. Test alternate tokens, inactive memberships, revoked/expired tokens and stale authorization.
- Logout revokes the presented API token. Test that the same token immediately fails after logout/revocation and that expired tokens are rejected.
- Append audit events for request, decision, posting, reversal, provider event quarantine, reconciliation decision and privileged access. Redact passwords, tokens, provider secrets, full account numbers and raw sensitive payloads.
- Audit evidence must include actor, business, subject/reference, decision/reason, timestamp, and safe request context. Financial audit history must not be silently editable by ordinary application roles.

## 5. Artifact inspection record — 2026-10-12

The CI artifact was downloaded and inspected for commit `ff55ac57acb6a2dac5a4533d438797c28e7e6d35` from workflow run `38240237545`.

- GitHub artifact: `semizzy-thrift-deployment`, artifact ID `11742837866`, reported size `6,167,031` bytes.
- Downloaded outer artifact SHA-256: `bdd62ff251838df0ad60f8e18589dbc358e8e3c879ffbe51b986839d44f1d99d` (matches the GitHub artifact digest).
- The outer artifact contains `semizzy-thrift-deployment.zip`; inner deployment ZIP SHA-256: `775c960d8ddb98aae07da45afda231fbe31767a0e6627d1dcf175fb03656c05f`.
- Inner ZIP passed `unzip -t`; it contains 6,484 entries, including application source, migrations, docs, `vendor/`, `artisan`, `composer.json`, `composer.lock`, `.env.example`, `public/app/index.html`, and compiled assets under `public/app/assets/`.
- The deployment package did not contain a live `.env`, `.git/`, `node_modules/`, tests, customer database dumps, or the live logs. `.env.example` is intentionally included.
- The expected frontend location is `public/app/`; this package does not use `public/build/manifest.json`.
- Artifact retention expiry shown by GitHub: 2026-10-26. Re-download and verify the checksum if using a later copy; do not assume a previously copied ZIP is identical.

**What this inspection proves:** the CI artifact exists, its reported digest matches the downloaded outer ZIP, the nested deployment ZIP is structurally valid, and the expected compiled frontend entry point/assets are packaged.

**What this inspection does not prove:** cPanel upload success, staging boot/smoke tests, production environment correctness, database backup integrity, restore success, rollback success, provider sandbox verification, or authorization to enable real-money operations. No backup/restore drill or cPanel staging deployment has been performed as part of this inspection.
## 6. cPanel release procedure (staging first)

### Before uploading

- Confirm the exact release commit and inspect the generated deployment ZIP contents. This application’s compiled frontend is `public/app/index.html` plus the files it references under `public/app/assets/` (not `public/build/manifest.json`). Verify the HTML and every referenced production asset are present; do not deploy a source-only ZIP.
- Verify the artifact has no .env, credentials, private keys, local database dumps, customer data, node_modules, or development-only secrets.
- Record the artifact checksum, commit SHA, workflow run URL, migration list and current application version in the release record.
- Confirm the cPanel document root points only to the Laravel public directory; keep application code, .env, backups and logs outside public web access.

### Backup and migration rehearsal

- Take a database backup using the hosting-supported database export and a separate encrypted backup of required uploaded files. Store backups outside the public document root; restrict permissions and access.
- Verify backup size and integrity, then restore into a separate staging database and file location. Record timestamp, operator, restore duration, and smoke-test results. A backup that has not been restored is not verified.
- Review migrations for destructive changes and table/index locks. Never assume a migration is reversible; prepare a forward-fix or a tested restore plan before running it.
- Run php artisan migrate:status and inspect pending migrations before applying any migration. Do not use migrate:fresh, destructive resets, or broad cache-clearing commands on production data.
- Use the site's configured PHP version and extensions. Run Laravel cache commands only after confirming .env is correct and backed up. Keep debug mode off and ensure logs are not web-accessible.

### Staging smoke tests

Record pass/fail and timestamp for:
- HTTPS certificate and canonical host; /api/v1/health.
- Register, email verification, login, logout, password reset and revoked-token rejection.
- Business membership and cross-tenant denial.
- Group/member/schedule/contribution read/write permissions and maker-checker confirmation.
- Reconciliation exception handling and audit events.
- Payment gateway catalogue confirms all live execution remains disabled.
- Deposit evidence review, payout decisions and contribution adjustment approval do not independently credit/debit balances.
- Storage/cache writable only where required; cron/queue strategy works without unsupported daemons; mail delivery and error alerts are verified.
- No secrets or sensitive personal/financial data appear in browser responses, public files, logs or artifacts.

### Rollback

- Stop scheduled/queue processing for the affected release if safe to do so; disable any affected provider before rollback.
- Preserve logs and the failed artifact for investigation. Do not delete or rewrite financial/audit records to make a rollback appear clean.
- Restore code and frontend assets as a matched artifact. If a migration has changed schema/data, use its tested rollback or forward-fix plan; restore the database only after assessing whether doing so would discard legitimate post-backup activity.
- Restore required uploads from the corresponding backup, verify permissions, clear Laravel caches, and run health/auth/tenant-isolation/disabled-financial-control smoke tests.
- Record who authorized rollback, exact artifact/commit, database action, time, observed impact and follow-up issue.

## Exit criteria

Issue #4 remains open until each applicable gate has concrete evidence from tests, reviewed configuration, provider sandbox output, a dated staging record, or a successful restore drill. A green CI run or an assembled ZIP alone does not authorize deployment or real-money operations.
