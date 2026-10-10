# SEMIZZY WEBMASTER — Thrift Collector Platform

Multi-tenant software for daily thrift collection and rotating Ajo/Esusu groups, initially targeting Nigeria and Ghana, with web and Android clients sharing a versioned backend API.

## Current stage

The Laravel 12/PHP foundation, versioned API, authentication and business-membership security foundations, country configuration, and automated checks are in progress. The React/TypeScript dashboard signs in through the existing API and loads the signed-in member's profile, active thrift groups, and that member's contribution records. Thrift group and contribution APIs are tenant-scoped, and contribution records distinguish recorded from confirmed without implying bank/provider settlement. Platform-admin provisioning and audit endpoints enforce explicit server-side permissions. The admin UI now loads protected read-only operational counts and audit events when the signed-in account has the required platform permission; privileged financial controls remain disabled. Contribution schedules, idempotent cycles and member obligations are implemented, while correction/reversal approvals remain audited decisions only. The complete product is not production-ready. Do not use this foundation to record real customer funds.

## Current domain/API scope

- Authenticated member dashboard: `GET /api/v1/dashboard/summary`. Returns active group memberships, the member's recent contribution records, and confirmed record totals grouped by currency; totals are integer minor units and are never combined across currencies.
- Business-scoped group API requires a valid `X-Business-Id`, verified email, active business membership, and role permission: `GET/POST /api/v1/business/groups`.
- Group membership management: `POST /api/v1/business/groups/{groupId}/members` adds an existing active business member; `DELETE /api/v1/business/groups/{groupId}/members/{userId}` deactivates a group membership without deleting history. Both require the business member-management permission and emit audit events.
- Contribution API: `GET/POST /api/v1/business/groups/{groupId}/contributions` and `POST /api/v1/business/groups/{groupId}/contributions/{recordId}/confirm`. Records use integer minor units, start in `recorded` status, and confirmation requires a different actor from the recorder. Confirmation is not deposit or settlement proof.
- Contribution schedules: `GET/POST /api/v1/business/groups/{groupId}/schedules`, `POST /api/v1/business/groups/{groupId}/schedules/{scheduleId}/generate-cycles`, and `GET /api/v1/business/groups/{groupId}/cycles`. Supports weekly, fortnightly and monthly date cycles; generation is idempotent and creates planning records only. It does not collect money or start a payment.
- Audited corrections/reversals: `POST /api/v1/business/groups/{groupId}/contributions/{recordId}/adjustments`, `GET /api/v1/business/groups/{groupId}/adjustments`, and `POST /api/v1/business/groups/{groupId}/adjustments/{adjustmentId}/decide`. A request requires a reason; a different authorized reviewer must approve/reject it. Approved decisions do not mutate original contribution rows and do not post ledger entries. A dedicated financial posting/reversal ledger is not implemented and must not be inferred from an approved request.
- Reconciliation and exception review: `GET/POST /api/v1/business/reconciliation/batches` and `POST /api/v1/business/reconciliation/entries/{entryId}/decision` require `reconciliation.manage`. Statement source/reference pairs are idempotent; repeated rows are retained and flagged as duplicates. Manual matching requires a confirmed contribution in the same business with exact amount and currency; references and dates are checked where provided. Unmatched, duplicate, partial and conflicting entries remain review states. Decision history is append-only at the application/API layer and each decision also emits a business audit event. Reconciliation does not mark settlement, mutate contribution values, post ledger entries, or execute payments. Database-level immutability enforcement and concurrent-production load verification remain release-gate work.
- Multiple dedicated deposit accounts per user: `GET /api/v1/business/payments/deposit-accounts` lists all dedicated accounts assigned to the signed-in member; `GET /api/v1/business/payments/deposit-accounts/banks` lists only banks the configured Paystack integration currently supports for dedicated accounts; `POST /api/v1/business/payments/deposit-accounts` requests another account for a selected bank, requires explicit user consent and an idempotency reference, and returns active or provisioning status. Each assigned account stores provider/customer references, bank, currency and provider account ID; account number and account name are encrypted at rest, and the account number hash prevents duplicate provider account assignments. Provisioning requires an administrator to explicitly set `public_config.virtual_accounts_enabled=true` on the Paystack deposit configuration; credentials alone do not enable it. Paystack dedicated virtual accounts are subject to provider onboarding, bank availability, account limits and customer-validation requirements. This increment does not yet include the provider webhook that finalizes asynchronous account assignment or deposit-credit settlement, and no account assignment is automatically treated as a financial credit.
- Bank account verification: `GET /api/v1/business/payments/banks` returns the current Paystack-supported Nigerian bank directory after an administrator stores Paystack credentials; `POST /api/v1/business/payments/accounts/resolve` resolves a 10-digit account number and bank code, returns the provider-verified account name, and stores a 10-minute verification record with account number and account name encrypted at rest. Manual payout requests require a fresh, user-owned verification record and store the verified bank details encrypted. The bank directory is provider-sourced, not a hardcoded claim that every Nigerian bank is supported; accounts the provider cannot resolve are rejected. This currently implements Paystack account-name resolution only; OPay, Monnify and Flutterwave account-verification adapters remain outstanding.
- Payment and payout workflow foundation: `GET /api/v1/business/payments/gateways`, `PUT /api/v1/business/payments/gateways/{provider}/{direction}` (stores encrypted credentials but forcibly keeps the provider disabled), `GET/POST /api/v1/business/payments/deposits`, `POST /api/v1/business/payments/deposits/{depositId}/review`, `GET/POST /api/v1/business/payments/payouts`, `POST /api/v1/business/payments/payouts/{payoutId}/decision`, and manual payment-evidence submission/review routes require a verified, active business membership; review routes require `payments.manage`. The catalogue lists Paystack, OPay, Monnify, Flutterwave and manual methods, with all live provider execution explicitly disabled. Manual deposits are evidence-review records only; manual payouts require a separate approver and independent evidence reviewer. Sensitive payout destinations and configured gateway credentials use Laravel encrypted casts. No wallet/ledger credit or debit, payment API call, webhook settlement confirmation, or live transfer is implemented by this workflow increment. Gateway-specific adapters, secret-management UI, sandbox tests, signed webhook handlers, provider requery, settlement reconciliation, and database-enforced audit immutability remain outstanding release gates.
- Protected admin dashboard: `GET /api/v1/admin/dashboard` requires `platform_role=admin` plus `platform.users.view`; it returns read-only operational counts and recent platform audit events. No financial execution endpoints are exposed.
- Platform provisioning: `POST /api/v1/admin/users/{userId}/provision` requires `platform.admins.manage`, a reason, and a different target from the acting admin. `GET /api/v1/admin/audit-events` requires `platform.audit.view`. Role/permission changes write a platform audit event. Registration cannot assign platform-admin privileges.
- Platform permissions must be granted to an existing platform administrator only through a restricted, reviewed operator process until a dedicated bootstrap/approval workflow exists. Newly registered users default to `member`; do not expose platform role or permission fields in public profile updates.

## Product principles

- Support individual thrift arrangements and rotating contribution groups.
- Support cash records and digital-payment records without conflating them.
- A cash entry is not proof of bank deposit or settlement.
- Payment authorization/success is not proof of provider settlement.
- A payout request or approval is not proof the recipient was paid.
- Financial corrections must be traceable; never silently overwrite posted history.
- Enforce tenant isolation and permissions on the server.
- Use maker-checker approval for high-risk actions; a requester cannot approve their own payout.
- Do not launch custody, lending, investment products, guaranteed returns, or cross-border transfers without explicit legal and operational approval.

## Target stack and hosting

- Backend: PHP 8.2+ and Laravel 12, versioned REST API.
- Web: React + TypeScript, built to static assets and served with the Laravel application.
- Android: React Native + TypeScript, using the same API.
- Database: MySQL/MariaDB.
- Hosting: cPanel/shared hosting first, with VPS support; no mandatory Docker, Redis, Supervisor, or root access.
- Scheduled tasks: cPanel Cron Jobs; database-backed queues where asynchronous jobs are needed.
- Responsive web UI: mobile-first user and admin layouts, with server-side authorization and viewport/accessibility checks.

## Local checks

Requirements: PHP 8.2+ and Composer 2.

    composer install
    npm install
    npm run build
    composer validate --no-check-publish
    composer lint
    composer test
    php artisan route:list --except-vendor
    php artisan migrate:fresh --seed --force

Run destructive migration commands only against a disposable local/test database, never production.

## Deployment and UI requirements

- [cPanel deployment and release checklist](docs/cpanel-deployment.md)
- [Responsive user/admin dashboard requirements](docs/responsive-dashboards.md)

Confirm the host's PHP CLI version, extensions, database version, document-root options, cron path, and resource limits rather than assuming a plan supports them. The current dashboard shell is an early UI scaffold; the admin screen is a protected, read-only operations view; it does not expose financial execution or privileged mutation controls. See the responsive dashboard specification for remaining acceptance checks.

## Product documentation

- [Architecture](docs/architecture.md)
- [Financial invariants](docs/financial-invariants.md)
- [Roadmap](docs/roadmap.md)

Do not enable a payment provider or capability until official documentation, sandbox behavior, webhook verification, transaction-status verification, settlement semantics, currencies, limits, and country/legal readiness have been checked and tested. Real payment execution and custody remain disabled until the security, reconciliation, and legal release gates pass.


## Dedicated deposit accounts and Paystack webhooks

Members can hold multiple provider-assigned deposit accounts per business workspace. The member dashboard's **Bank accounts** section loads the authenticated member's accounts and provider-supported bank choices, and offers an **Add another bank account** request. Every request uses a unique client reference and explicit consent. Account numbers and account names are encrypted at rest.

Dedicated account provisioning stays unavailable unless a business administrator has configured Paystack credentials and explicitly sets `public_config.virtual_accounts_enabled=true`. Provider availability, customer eligibility and supported banks remain subject to Paystack approval; this is not a promise of all-bank coverage.

Configure Paystack to send signed webhooks to `POST /api/v1/webhooks/paystack`. The endpoint verifies the `x-paystack-signature` HMAC against configured Paystack secret keys before processing events. It handles `dedicated_account.assign.success` and assignment failure events to reconcile pending account assignments. `charge.success` events are recorded as provider-deposit evidence, with idempotent provider event/transaction records and an account match where possible.

Business members with the `reconciliation.manage` permission can inspect provider deposits through `GET /api/v1/business/payments/provider-deposits` and record an independent evidence decision through `POST /api/v1/business/payments/provider-deposits/{eventId}/review`. Decisions are immutable after first review; corrections need a separately audited correction process.

**Important financial release boundary:** signed webhook receipt and a successful reconciliation evidence decision do not prove provider settlement and do not credit a wallet, contribution, or ledger. Automated settlement verification, independently reviewed ledger posting/reversal workflows, sandbox certification, and production release approval remain separate gates. Do not enable live money movement based only on this webhook increment.


### Provider deposit review and pending-account recovery

The web dashboard includes a **Deposit review** queue for business owners, managers, and auditors with `reconciliation.manage`. Other workspace roles are not shown the navigation entry, and the API independently enforces the same permission. Review decisions require a reason, are recorded in the payment-operation audit trail, and cannot be self-approved by the account holder.

The `payments:recover-deposit-accounts` Artisan command is scheduled every ten minutes and can also be run manually. It checks only Paystack accounts still in `provisioning` for at least ten minutes, re-queries the existing provider account/customer, and updates the existing row only after provider data confirms assignment. It never creates another provider assignment and never posts a balance or ledger credit. Attempts are throttled to at most 12 per account, with recovery timestamps/results retained for support diagnostics.

For cPanel, configure Laravel's scheduler with a cron entry that runs `php /path/to/project/artisan schedule:run` every minute, using the hosting account's actual PHP CLI path and application directory. The scheduled recovery task itself runs every ten minutes. Check the command output and Laravel logs when an account remains pending; after the retry limit, support must investigate provider/customer/bank matching rather than automatically creating a replacement account.


### Pending account operations and transaction PIN

Authorized business reviewers with `reconciliation.manage` can open **Account operations** in the member dashboard. The API lists only long-running/repeated Paystack assignments and exposes safe diagnostics (age, retry count, last result, presence—not values—of provider references, and a diagnostic code). It never returns account numbers, account names, provider credentials, or PIN data. The reviewer can record a reasoned support escalation; an account can have only one escalation record, and the action is audited. Escalation does not create another provider account or post financial entries.

Members can set a four-digit transaction PIN under **Security & PIN**. PIN hashes are stored with Laravel's password hashing, never returned to clients, and five consecutive wrong transaction PIN attempts lock payment submissions for 15 minutes. Manual deposit and payout requests require both the PIN and a human-readable `purpose`, which is stored and audited. PIN changes currently require account-password reauthentication and the current PIN; email OTP verification for PIN changes is not yet integrated, so this is not the final security release gate. Keep live money movement disabled until the OTP/change-verification flow and all financial release gates are complete.


### Member deposit and payout requests

The member dashboard's **Deposit & payout** screen uses the authenticated workspace APIs to create manual-review requests and list each member's latest 25 deposit and payout requests. Both request forms require an amount, a human-readable purpose, and the transaction PIN. A blank reference is generated client-side; a supplied reference is used for idempotency and cannot be reused with changed amount/purpose details. Payout submission first resolves the selected Nigerian bank and 10-digit account number through the configured Paystack account-name lookup, then submits the verification record ID to the manual payout request API.

Request history shows request status, reference, purpose, amount, and available reviewer notes. The screen does not treat approval as proof of settlement. Provider-based live deposit/payout execution remains disabled, and these manual request flows never credit a wallet or initiate a bank transfer.


### Member savings wallet and withdrawal ledger

Member wallets are scoped by business, user, and currency. The ledger uses integer minor units and immutable, balanced debit/credit journals with business-scoped idempotency keys. A contribution is credited only after independent confirmation for cash collections, or after an authorized exact-match decision in the reconciliation workflow for non-cash collections. Merely recording a collection, creating a deposit request, or receiving an unreviewed provider webhook does not credit the wallet.

A manual withdrawal request requires a verified destination account, transaction PIN, purpose, and sufficient wallet balance. The wallet is debited in the same database transaction that creates the payout request; duplicate idempotency references cannot debit it twice. Independent rejection or a separately reviewed failure before transfer evidence is submitted posts a compensating refund exactly once. A payout with ambiguous transfer evidence stays debited until the transfer is reconciled. Successful settlement is recorded only after independent bank-statement review, and settlement does not debit the wallet again.

Member endpoints (under the authenticated business API):
- `GET /api/v1/business/wallet?currency_code=NGN`
- `GET /api/v1/business/wallet/ledger?currency_code=NGN&limit=20`

Withdrawal lifecycle endpoints include the existing request/approval/evidence routes plus:
- `POST /api/v1/business/payments/payouts/{id}/fail` — independently record a confirmed failed transfer before transfer evidence has been submitted; refunds the wallet once.
- `POST /api/v1/business/payments/payouts/{id}/settle` — record successful settlement only after independent bank-statement review.

Automatic provider withdrawals and live bank transfer execution remain disabled. The manual lifecycle records requests, approvals, evidence, settlement attestations, and wallet journals; it does not itself initiate a bank transfer. Re-run the full CI suite and complete the financial release gates before deployment or enabling live money movement.
