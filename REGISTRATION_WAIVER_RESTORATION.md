# Registration waiver restoration — 2026-09-27

## Scope and cause

Reception already contained the waiver, discount and tax controls. The separate Patients registration form did not. Restored those fields and their POST bindings in Patients using the existing Reception implementation. Calendar code was not replaced.

## Files changed in this restoration

- `auth/pages/patients.php`: Free Consultation, discount type/value/reason, tax, final amount; form state and POST bindings; live recalculation.
- `auth/pages/reception.php`: zero payment limit when waived and correct handling of No discount when an old discount value remains.
- `auth/assets/clinic.js`: appointment payment guard displays zero and “Free consultation — no payment required” for a checked waiver.
- `scripts/registration_waiver_check.php`: rollback-only local backend regression.

## Existing backend reused

The existing `tdc_create_patient_appointment` helper enforces the visit-level `IsFreeConsultation` flag, overrides submitted payment/discount/tax for waived visits, saves zero consultation fee/paid/due, and does not create a payment or post revenue for a zero amount. Doctor fees are not modified. No schema changes or new waiver model were introduced. Revisit business logic was not changed.

## Verification

- Live Patients registration: fee 20, due 20 with waiver off; zero totals with waiver on.
- Live doctor change while waived: totals remain zero; unchecking restores the new doctor's actual fee (100).
- Live discount/tax: fee 100 less fixed discount 10 plus 5% tax gives final/due 94.50.
- Live date/time selection: 28 September 2026 at 10:30 remains selected after checking Free Consultation.
- Payment, discount, tax and reason inputs disabled while waived; paid displays zero.
- Backend regression: manipulated amount 999 and discount/tax 99 overridden; saved zero fee/paid/due with waiver flag, no payment; normal visit afterward retains doctor fee. All writes rolled back.
- Existing calendar backend negative tests passed: unavailable weekday, before/after working hours, past date, missing date rejected.
- PHP syntax checks for both pages and JavaScript syntax check for clinic.js passed.

## Limits

Local verification only; no production deployment. No durable patient or appointment was created in this restoration pass. Full browser save/reload, reports, audit-log and whole-system matrices were not rerun. Existing reporting code recognizes the waiver flag; this pass's accounting evidence is the saved zero visit values and absence of a payment. Browser screenshot capture was clipped by the current browser surface, so visual layout certification is not claimed.
