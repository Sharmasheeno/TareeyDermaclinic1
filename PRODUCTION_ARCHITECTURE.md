# Production Architecture

## Payment policy

All payments are manual records. Staff confirm money was received outside the application and then record the amount and method. The application performs no EVC Plus, telecom, bank, card, Stripe, PayPal, webhook, callback, or payment-gateway request.

A payment method such as Cash, EVC Plus, Mobile Money, Card, Bank Transfer, or a custom Setup value is an internal label only.

## Authoritative workflows

- **Patient:** Reception owns registration and patient identity. Patient history reads consultations, laboratory, pharmacy, and payment records.
- **Consultation:** Reception creates the visit and records an optional manual payment. A fully paid visit enters the doctor queue. Doctor owns clinical authoring.
- **Booking safety:** Doctor schedules use a 30-minute appointment policy. Server-side working-hours validation, overlap detection, and a per-doctor database advisory lock run inside the booking transaction.
- **Laboratory:** Doctor requests a configured service. Reception records manual payment. Laboratory works only paid/authorized orders and owns result completion. Doctor reviews returned results.
- **Prescription:** Doctor creates a clinical prescription without stock deduction. Pharmacy must validate inventory and calculate price before dispensing. This lifecycle still requires consolidation with Reception pharmacy billing before release.
- **POS:** Walk-in POS is separate from patient prescriptions. Inventory, payment, sale, and accounting must be one transaction.
- **Purchase:** Pharmacy purchase receipt must update stock, supplier payable/payment, accounting, and audit atomically. Acquisition costs are SuperAdmin-only.
- **Accounting:** Automatic receipts must post balanced debit/credit batches. Payment methods select internal receiving asset labels; they never trigger external processing.
- **Corrections:** POS voids preserve the original accounting batch and add a reversing batch. A general manual-payment reversal workflow remains required before release.
- **Notifications:** Exact known recipients should use UserID. Functional groups should be resolved from permissions rather than only the built-in role string.

## Roles

- **SuperAdmin:** Full access, including Setup, users, roles, permissions, acquisition costs, and reports.
- **Reception:** Patient, appointment, consultation/lab/pharmacy collection, operational laboratory/pharmacy workflows, accounting collections, and operational reports. No user/role administration or acquisition-cost visibility.
- **Doctor:** Assigned consultations, clinical records, prescriptions, lab requests, and returned results.
- **Laboratory:** Authorized paid work, processing, results, and completion.
- **Pharmacy:** Prescriptions, dispensing, POS, inventory, and permitted purchasing operations.

## Transaction boundaries

These must commit or roll back as a unit:

- consultation booking plus source obligation and payment/accounting entries
- every manual payment plus bill status, patient balance, accounting, and audit
- laboratory payment clearance
- prescription sale/dispensing plus stock deduction
- POS sale plus payment, stock, accounting, and receipt
- purchase plus inventory, payable/payment, accounting, and audit
- payment reversal plus reversal accounting and audit

## Current release limitation

Patient balances are still stored and updated by several modules. A derived balance/reconciliation service is required before production release. Pharmacy Reception and Pharmacy lifecycle duplication and booking collision protection also remain release blockers.
