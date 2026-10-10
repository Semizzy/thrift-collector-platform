# Architecture and trust boundaries

## Product scope

The first release supports collector businesses managing personal/daily thrift arrangements, rotating Ajo/Esusu groups, memberships and cycles, contribution schedules, cash and digital payment records, receipts, statements, handovers, reconciliation, disputes, audit history, staff permissions and maker-checker approvals. Contributor-facing web pages and an Android client use the same API.

The initial countries are Nigeria (NGN) and Ghana (GHS). Country configuration must be explicit; currency must never be inferred from display locale.

## Proposed application shape

- Laravel API: authentication, tenant authorization, domain services, country configuration, audit events, queue-compatible jobs and versioned REST endpoints.
- MySQL/MariaDB: transactional records, tenant-scoped data, idempotency keys, audit history and accounting journals.
- React + TypeScript web client.
- React Native + TypeScript Android client.
- Provider adapters declare capabilities; no guessed endpoints or unverified capabilities.

Shared business logic belongs on the server. Client-side checks improve usability but never replace authorization.

## Tenancy and identity

Every business-owned record must have an explicit business scope or a provable relationship to one. Every request must authorize both actor and target record. Never trust a client-supplied business ID by itself. Cross-tenant reads and writes must fail closed.

Initial roles: Platform Owner, Platform Operations, Compliance Reviewer, Collector Business Owner, Collector Manager, Field Agent, Contributor, Group Organizer and Auditor. Use least privilege. Financial corrections and payouts require explicit permissions and audit events. A requester must not approve their own payout.

## Financial trust boundaries

Keep these events distinct: expected contribution; contribution recorded by a collector/agent; contributor confirmation or dispute; digital payment provider success; provider settlement; cash handover and acknowledgment; reconciled funds; payout request, approval, provider submission and confirmed payment.

No event may imply a later event without evidence. A recorded cash collection cannot create a provider settlement record.

## Data model domains

Planned domains include countries, businesses, users, memberships, roles/permissions, customers, thrift products, arrangements, groups, group memberships, group cycles, contribution schedules/records, payment attempts, cash handovers, payouts/executions, ledger accounts, journal entries/lines, provider settlements, reconciliation items, disputes, audit events, consents, notifications, subscriptions, invoices, provider credentials, webhook events/attempts, attachments, cash counts, security sessions and retention policies.

Before implementing each table, define ownership, status transitions, uniqueness constraints, deletion/retention behavior and indexes. Avoid creating the entire schema as one unreviewed migration.

## API conventions

Use /api/v1/...; return stable machine-readable error codes; validate inputs server-side; scope every query; support idempotency on mutation endpoints; rate-limit authentication and sensitive operations; verify webhook signatures; store provider event IDs uniquely; and make retries safe.

Candidate endpoint families: auth, businesses/memberships, customers, arrangements, groups/cycles, contribution records, payment intents, cash handovers, payouts/approvals, reconciliation runs, statements, disputes, subscriptions and provider webhooks.

## Hosting and operations

Keep deployment compatible with cPanel/shared hosting and VPS environments. Use database-backed queues and cPanel cron where asynchronous work is needed; do not make Redis, Docker, Supervisor, systemd, or root privileges mandatory. Include backup and restore drills, structured logs, secret management, monitoring and incident procedures before pilot launch.

## Explicit non-goals for initial release

Do not hold pooled customer funds, lend, sell investment products, promise returns, or execute cross-border transfers until specialist legal review, licensing/partner requirements, operational controls and country-specific release gates are satisfied.