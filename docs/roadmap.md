# Delivery roadmap

## Sprint 0 — foundation
- Repository conventions, architecture and financial invariants.
- PHP domain package and automated CI.
- Money value object and unit tests.
- Define Laravel application bootstrap, database conventions, migrations and local setup.
- Gate: CI green; money/currency behavior tested; architecture and trust boundaries reviewed.

## Sprint 1 — identity, businesses and authorization
- Laravel application bootstrap, environment configuration and database health checks.
- Registration, login, recovery and secure session/token handling.
- Nigeria/Ghana country and currency configuration.
- Business onboarding, memberships, roles/permissions and tenant-scoped query policies.
- Audit-event foundation and security tests.
- Gate: create a business, add members, enforce least privilege and prove cross-tenant denial. No financial posting or payouts yet.

## Sprint 2 — thrift arrangements and groups
- Customers/contributors, personal arrangements, schedules, rotating groups, membership and cycle/rule versioning.
- Enforce group-rule history and payout-order versioning.
- Gate: schedule calculations and group lifecycle tests pass.

## Sprint 3 — collection records and contributor portal
- Cash/digital record capture, receipts, statements, confirmation and dispute flows.
- Offline cash capture uses unique local IDs and pending sync; offline actions cannot authorize payouts.
- Gate: record states remain distinct and duplicates are safely handled.

## Sprint 4 — accounting evidence and reconciliation
- Cash counts/handovers, provider settlement imports, reconciliation exceptions, immutable journal model and correction/reversal flows.
- Gate: critical financial tests, balancing, concurrency and restore tests pass.

## Sprint 5 — web and Android experience
- React/TypeScript web workspace and React Native/TypeScript Android app on the shared API.
- Notifications, accessible forms, resilient offline capture and secure local storage.
- Gate: permission-aware end-to-end tests on both clients.

## Sprint 6 — controlled Nigeria/Ghana pilot
- Pilot with a small, consented group of collectors and contributors.
- Reconcile sampled records daily; test disputes, support escalation, backup restore and incident response.
- Suggested targets to validate, not current results: recurring pain with at least 12/20 interviewed collectors; benefit recognized by 20/30 contributors; 10 collectors testing; at least 70% week-four retention; at least 99% sampled record reconciliation; zero unresolved material platform-attributable financial discrepancies; five willingness-to-pay commitments.

## Sprint 7 — payments and expansion
- Enable only individually verified provider capabilities after official-doc/sandbox tests, settlement reconciliation, security review and country-specific legal sign-off.
- Review custody, payout, lending, investment and cross-border capabilities separately; they are not automatically in scope.

## Global release gates
- All critical financial and authorization tests pass.
- No unresolved critical/high security issues.
- Backup restore has been demonstrated.
- Provider capabilities are evidenced and default to disabled/unverified.
- Nigeria/Ghana legal review and incident procedures are complete before real-money processing.
- No release claims unimplemented features as complete.