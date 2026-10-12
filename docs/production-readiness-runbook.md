# Production Readiness Runbook — cPanel Staging

**Status:** preparation checklist only. This document is not evidence that a restore drill, production smoke test, or real-money release has occurred.

## Non-negotiable launch mode

Until the production-readiness issue is explicitly signed off, run this application as a controlled non-financial beta. Do not accept, hold, promise, or transfer real customer funds through this foundation. Keep all payment provider execution disabled. An approval status, uploaded receipt, provider API acceptance, webhook delivery, or reconciliation match is not proof of settlement and must not credit a wallet by itself.

## 1. Ledger and contribution-adjustment design gate

Contribution adjustment approval must remain a decision record only until a separately reviewed posting implementation is merged. Do not mutate a posted contribution row or rewrite prior ledger entries.

Required posting contract before implementation is enabled:

- Use integer minor units and one ISO-style three-letter currency per journal. Never convert or aggregate balances across currencies implicitly.
- Post a balanced journal atomically: total debits equal total credits, with at least two lines, all amounts positive, and all accounts scoped to the same business and currency.
- Use a unique, deterministic idempotency key per adjustment decision and posting version. Replays with identical details return the original transaction; reuse with different details fails closed.
- Lock the contribution/adjustment and affected accounts in a stable order inside one database transaction. Re-check pending/approved state under lock. A requester cannot decide their own request; a decision cannot be posted twice.
- A reversal must be a new journal that exactly offsets the original posted journal, references its source transaction, and cannot exceed the unreversed amount. A correction must be an explicit delta/reversal-and-replacement journal; it must not overwrite history.
- Approval and posting are separate states. A posting failure leaves the decision visible and retryable, but must never leave a partially posted journal. Do not mark an adjustment financially applied until the journal commits.
- Add database-enforced uniqueness, foreign keys, positive-amount and currency constraints where supported. The application-level balanced-journal check is not a substitute for schema constraints or tests.
- Add tests for same-actor rejection, double approval, repeated jobs, conflicting idempotency payloads, over-reversal, cross-business IDs, currency mismatch, rollback after injected failure, and concurrent duplicate posting.

**Current known limitation:** the existing adjustment workflow explicitly records approval without ledger posting. Do not describe it as a completed financial reversal.

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
- Enforce role permissions on the server, not just navigation/UI. Platform-wide oversight remains read-only unless a separate explicit permission and audited workflow is reviewed.
- The requester must not approve their own deposit evidence, payout, adjustment, refund, or privileged account change. Test alternate tokens, inactive memberships, revoked/expired tokens and stale authorization.
- Logout revokes the presented API token. Test that the same token immediately fails after logout/revocation and that expired tokens are rejected.
- Append audit events for request, decision, posting, reversal, provider event quarantine, reconciliation decision and privileged access. Redact passwords, tokens, provider secrets, full account numbers and raw sensitive payloads.
- Audit evidence must include actor, business, subject/reference, decision/reason, timestamp, and safe request context. Financial audit history must not be silently editable by ordinary application roles.

## 5. cPanel release procedure (staging first)

### Before uploading

- Confirm the exact release commit and inspect the generated deployment ZIP contents. Verify public/build/manifest.json and every referenced production asset are present; do not deploy a source-only ZIP when the frontend needs a compiled build.
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
