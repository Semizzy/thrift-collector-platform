# SEMIZZY WEBMASTER — Thrift Collector Platform

Multi-tenant software for daily thrift collection and rotating Ajo/Esusu groups, initially targeting Nigeria and Ghana, with web and Android clients sharing a versioned backend API.

## Current stage

Sprint 0 — foundation. This repository establishes architecture and financial-safety rules plus the first tested money value object. It is not production-ready and must not be used to record real customer funds.

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

## Proposed stack

- Backend: PHP 8.2+ and Laravel 12, versioned REST API.
- Web: React + TypeScript.
- Android: React Native + TypeScript.
- Database: MySQL/MariaDB.
- Hosting: Nigerian cPanel/shared hosting first, with VPS support; no mandatory Docker, Redis, Supervisor, or root access.

The full Laravel application scaffold and clients have not yet been implemented.

## Local checks

Requirements: PHP 8.2+ and Composer 2.

    composer install
    composer test
    composer lint

## Product documentation

- docs/architecture.md
- docs/financial-invariants.md
- docs/roadmap.md

Do not enable a payment provider or capability until official documentation, sandbox behavior, webhook verification, transaction-status verification, settlement semantics, currencies, limits, and country/legal readiness have been checked and tested.