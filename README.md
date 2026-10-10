# SEMIZZY WEBMASTER — Thrift Collector Platform

Multi-tenant software for daily thrift collection and rotating Ajo/Esusu groups, initially targeting Nigeria and Ghana, with web and Android clients sharing a versioned backend API.

## Current stage

The Laravel 12/PHP foundation, versioned API, authentication and business-membership security foundations, country configuration, and automated checks are in progress. The complete product and clients are not yet production-ready. Do not use this foundation to record real customer funds.

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

## Local checks

Requirements: PHP 8.2+ and Composer 2.

    composer install
    composer validate --no-check-publish
    composer lint
    composer test
    php artisan route:list --except-vendor
    php artisan migrate:fresh --seed --force

Run destructive migration commands only against a disposable local/test database, never production.

## cPanel deployment

Read [the cPanel deployment and release checklist](docs/cpanel-deployment.md) before configuring a hosting account. Confirm the host's PHP CLI version, extensions, database version, document-root options, cron path, and resource limits rather than assuming a plan supports them.

## Product documentation

- [Architecture](docs/architecture.md)
- [Financial invariants](docs/financial-invariants.md)
- [Roadmap](docs/roadmap.md)
- [cPanel deployment and release checklist](docs/cpanel-deployment.md)

Do not enable a payment provider or capability until official documentation, sandbox behavior, webhook verification, transaction-status verification, settlement semantics, currencies, limits, and country/legal readiness have been checked and tested. Real payment execution and custody remain disabled until the security, reconciliation, and legal release gates pass.
