# SEMIZZY WEBMASTER — Thrift Collector Platform

Multi-tenant software for daily thrift collection and rotating Ajo/Esusu groups, initially targeting Nigeria and Ghana, with web and Android clients sharing a versioned backend API.

## Current stage

The Laravel 12/PHP foundation, versioned API, authentication and business-membership security foundations, country configuration, and automated checks are in progress. The React/TypeScript dashboard signs in through the existing API and loads the signed-in member's profile, active thrift groups, and that member's contribution records. Thrift group and contribution APIs are tenant-scoped, and contribution records distinguish recorded from confirmed without implying bank/provider settlement. Platform-admin provisioning and audit endpoints enforce explicit server-side permissions; the admin UI remains preview-only, with no live privileged controls or administrative metrics. The complete product is not production-ready. Do not use this foundation to record real customer funds.

## Current domain/API scope

- Authenticated member dashboard: `GET /api/v1/dashboard/summary`. Returns active group memberships, the member's recent contribution records, and confirmed record totals grouped by currency; totals are integer minor units and are never combined across currencies.
- Business-scoped group API requires a valid `X-Business-Id`, verified email, active business membership, and role permission: `GET/POST /api/v1/business/groups`.
- Group membership management: `POST /api/v1/business/groups/{groupId}/members` adds an existing active business member; `DELETE /api/v1/business/groups/{groupId}/members/{userId}` deactivates a group membership without deleting history. Both require the business member-management permission and emit audit events.
- Contribution API: `GET/POST /api/v1/business/groups/{groupId}/contributions` and `POST /api/v1/business/groups/{groupId}/contributions/{recordId}/confirm`. Records use integer minor units, start in `recorded` status, and confirmation requires a different actor from the recorder. Confirmation is not deposit or settlement proof.
- Contribution schedules: `GET/POST /api/v1/business/groups/{groupId}/schedules`, `POST /api/v1/business/groups/{groupId}/schedules/{scheduleId}/generate-cycles`, and `GET /api/v1/business/groups/{groupId}/cycles`. Supports weekly, fortnightly and monthly date cycles; generation is idempotent and creates planning records only. It does not collect money or start a payment.
- Audited corrections/reversals: `POST /api/v1/business/groups/{groupId}/contributions/{recordId}/adjustments`, `GET /api/v1/business/groups/{groupId}/adjustments`, and `POST /api/v1/business/groups/{groupId}/adjustments/{adjustmentId}/decide`. A request requires a reason; a different authorized reviewer must approve/reject it. Approved decisions do not mutate original contribution rows and do not post ledger entries. A dedicated financial posting/reversal ledger is not implemented and must not be inferred from an approved request.
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

Confirm the host's PHP CLI version, extensions, database version, document-root options, cron path, and resource limits rather than assuming a plan supports them. The current dashboard shell is an early UI scaffold; the admin screen is preview-only and must not be treated as a protected administration interface. See the responsive dashboard specification for remaining acceptance checks.

## Product documentation

- [Architecture](docs/architecture.md)
- [Financial invariants](docs/financial-invariants.md)
- [Roadmap](docs/roadmap.md)

Do not enable a payment provider or capability until official documentation, sandbox behavior, webhook verification, transaction-status verification, settlement semantics, currencies, limits, and country/legal readiness have been checked and tested. Real payment execution and custody remain disabled until the security, reconciliation, and legal release gates pass.
