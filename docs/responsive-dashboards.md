# Responsive dashboard implementation requirements

This specification is the acceptance checklist for the upcoming user and platform-admin dashboard UI. The repository contains a Laravel API/auth foundation and an initial React/TypeScript responsive dashboard scaffold. The current user dashboard deliberately shows empty states until real endpoints are integrated. The admin screen is a preview only, with no live data or privileged actions; it is not production admin authorization.

## Supported viewports

Test at minimum:
- 320px and 360px mobile widths
- 390px and 430px mobile widths
- 768px tablet portrait
- 1024px tablet/compact laptop
- 1280px and 1440px desktop

No horizontal page scrolling at supported widths, except within a deliberately bounded data table region when a table cannot reasonably collapse into cards.

## Shared responsive shell

- Use a mobile-first layout and shared design tokens for spacing, typography, surfaces, borders, and focus states.
- Mobile: compact top bar, accessible menu button, slide-in or collapsible navigation, and a single-column content flow.
- Tablet: navigation may collapse to an icon rail or drawer; dashboard cards use two columns when space permits.
- Desktop: persistent sidebar, clear page title/action area, and fluid content constrained to a readable maximum width.
- Keep all navigation controls keyboard accessible with visible focus indicators, semantic landmarks, accessible names, and adequate touch targets (aim for 44×44 CSS px).
- Respect reduced-motion preferences and avoid hover-only interactions.
- Handle long business names, large amounts, long table values, empty states, loading states, and API errors without breaking the layout.

## User dashboard

- Prioritize current business/group context and quick access to contribution records, thrift arrangements, group cycles, receipts/history, disputes/support, profile, and notifications as those features become available.
- Use responsive summary cards; never rely on color alone to convey financial status.
- Keep currency codes visible with monetary amounts and do not imply that recorded cash is settled funds.
- On mobile, stack primary actions and make key actions reachable without horizontal scrolling.
- Show helpful empty/loading/error states and do not fabricate balances, contribution totals, or transactions when the API has no data.

## Platform admin dashboard

- Separate platform-level administration from collector-business dashboards.
- Show only metrics backed by real, authorized API responses; no fake counts or seeded production-looking financial data.
- Make user/business review, country configuration, operational health, audit history, and access-controlled management areas responsive as they are implemented.
- Use a collapsible navigation drawer on mobile; keep dangerous actions clearly labeled and require appropriate authorization/confirmation.
- Never place secrets, raw provider credentials, or sensitive personal/financial data into dashboard HTML, logs, or client-side bundles.
- Enforce authorization server-side for every admin route and API endpoint. Hiding an admin link in the UI is not access control.

## Responsive data tables

- At mobile widths, convert row data to labelled cards where practical. If a table must remain tabular, keep it inside an explicitly labelled horizontal-scroll container and preserve row/column context.
- Prevent action buttons from overflowing; use a clearly labelled row action menu where appropriate.
- Filters, search, pagination, and export controls must wrap/stack on narrow screens.
- Never silently remove critical columns or security-relevant status information on mobile.

## Accessibility and quality gates

- Verify layout at the viewport widths above, with keyboard-only navigation and visible focus.
- Check contrast, accessible labels, heading hierarchy, form errors, status announcements, and touch target size.
- Verify no horizontal overflow on dashboards, dialogs, forms, cards, navigation, and tables.
- Test loading, empty, error, long-content, and unauthorized states.
- Confirm mobile navigation opens/closes and returns focus correctly.
- Verify all figures are API-backed and tenant-scoped; ensure the admin dashboard is protected by server-side admin authorization before exposing it.
- Keep UI tests in CI and do not claim visual/browser verification unless an automated browser test or actual device test has run.

## Implementation sequence

1. Establish shared responsive app shell and tokens.
2. Implement user dashboard using real authenticated endpoints and explicit empty/loading/error states.
3. Implement admin dashboard only after platform-admin identity, permissions, and protected routes are defined.
4. Add component/UI tests and browser viewport tests.
5. Run CI, repair failures, and verify a production build before cPanel deployment.
