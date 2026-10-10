# Financial invariants and state distinctions

These rules are release-blocking. UI labels, APIs, reports, provider adapters and tests must preserve them.

## Separate facts

| Concept | Meaning | Does not prove |
|---|---|---|
| Expected contribution | Amount due under a schedule | That anyone paid |
| Recorded contribution | Collector/agent entered a record | Contributor agreement, bank deposit or settlement |
| Confirmed contribution | Confirmation evidence was captured | Provider settlement unless independently established |
| Payment successful | Provider reports a successful payment event | Settlement to the relevant business account |
| Provider settled | Reliable provider/bank settlement evidence was reconciled | A separate payout was completed |
| Payout requested | Someone asked for a payout | Approval, submission or payment |
| Payout approved | Authorized reviewer approved it | Provider execution or recipient receipt |
| Confirmed paid | Sufficient execution evidence was checked | That every other record is reconciled |

Cash entry must never create provider-settlement evidence. Provider success must not be silently mapped to settlement. A failed or unknown provider status must never be shown as paid.

## Money representation

- Store amounts as integer minor units or fixed-precision database decimals; never use binary floating-point for money.
- Always pair an amount with an explicit ISO currency code.
- Never compare or add amounts across currencies without an explicit, auditable conversion record.
- Reject overflow, unsupported currencies and invalid precision at boundaries.
- Journal postings must balance: total debits equal total credits in the same currency.
- Posted journal entries are immutable. Correct mistakes with linked reversal/correction entries, not destructive edits.
- Do not post accounting journals merely because a user entered a collection amount.
- Keep business revenue and customer/custodial funds separate.

## Suggested state machines

Contribution: draft, recorded, pending_confirmation, confirmed; disputed may be entered from applicable reviewable states. Corrections use traceable reversal/correction flows.

Digital payment: initiated, pending, successful_unsettled, settled; other outcomes include failed, reversed and refunded. Only verified provider semantics can drive transitions.

Cash handover: draft, submitted, acknowledged, reconciled; discrepancies can move it to disputed and then a traceable resolution.

Payout: requested, under_review, approved, submitted_to_provider, processing, confirmed_paid; alternatives include failed, cancelled and reversed. Each transition must be permission-checked and audited.

Reconciliation: unmatched, matched, partial_match, exception, resolved.

Transitions validate previous state, actor permissions, evidence requirements and idempotency. Terminal states cannot be silently reopened.

## Idempotency and provider events

- Require idempotency keys for sensitive creation/submission endpoints and enforce database uniqueness in the correct tenant/operation scope.
- Store provider event identifiers uniquely; verify webhook signatures before accepting payloads.
- Make webhook processing safe under duplicate delivery and concurrent retries.
- A webhook is not automatically proof of settlement; interpret it according to verified provider documentation and corroborating settlement data.
- Unknown status, mismatched amount/currency, duplicate reference or missing settlement evidence creates an exception, not optimistic success.

## Audit and approvals

Audit events record actor, tenant, action, target, timestamp, correlation/idempotency reference and relevant before/after values, with secrets and sensitive identity data redacted. Financial history cannot be silently edited or deleted. High-risk actions require maker-checker approval, and the requester cannot approve their own payout.

## Release-blocking tests

Test that cash contributions never create settlement; NGN and GHS cannot be mixed; duplicate requests are idempotent; duplicate/invalid webhooks are safely handled; unbalanced journals cannot post; reversal history is retained; concurrent submissions cannot double-apply an event; self-approval and cross-tenant access are denied; failed/unknown payouts never show as paid; partial settlements and reconciliation exceptions remain visible; and backups can be restored and validated.