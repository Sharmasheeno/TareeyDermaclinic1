# Appointment calendar verification — 26 September 2026

Scope: local application at 127.0.0.1:8000. No production deployment performed.

## Root cause

Reception's later date-only conversion changed `pf_AppointmentDate` from `datetime-local` to `date`. Calendar click handlers assigned `YYYY-MM-DDTHH:mm`; the date input rejected it and became empty. The previous renderer then selected a fallback date, masking the failed write. Native and custom date controls were also both visible. Earlier month-navigation patches addressed the symptom but did not fix this input-type conflict.

## Implementation

One shared custom calendar owns date/time selection. The submitted hidden AppointmentDate/VisitDate holds the combined datetime. Date clicks clear the time; time clicks select exactly one slot. Month browsing preserves the selection. Doctor changes clear invalid selections. The same component is used by Reception registration, Patients registration/booking, Appointment Info Edit, and Revisit.

Source: doctors.DoctorID, WorkingDays (ISO 1=Monday through 7=Sunday), WorkStartTime, WorkEndTime. Frontend `TdcAvailability.schedule`, `availableDay`, and `TdcAppointmentCalendar` use option data rendered from these fields. Backend `tdc_doctor_is_available`, `tdc_doctor_has_booking_conflict`, `tdc_create_patient_appointment`, and `tdc_update_patient_appointment` remain authoritative. Slot interval comes from `TDC_APPOINTMENT_MINUTES` (30), not a second frontend constant.

Existing backend rules permit a start exactly at closing time; the UI preserves that inclusive boundary. Legacy date-only backend/import input remains supported. New calendar submissions require explicit date and time. Bookings are loaded beyond the previous 42-day cutoff; overlapping 30-minute slots are disabled. Server checks still decide conflicts at submission.

## Results

| Check | Result |
|---|---|
| Doctor availability load | PASS — stored weekday doctor vs all-days E2E doctor |
| Working days | PASS — Monday/Friday enabled, Saturday/Sunday disabled for weekday doctor |
| Calendar date click | PASS — 2 October and 28/29 September selected live |
| Selected date display | PASS — readable date and pressed state |
| Disabled days | PASS — native disabled buttons; past and non-working dates blocked |
| Month navigation | PASS — next/previous retain selection |
| Time slot generation | PASS — configured hours, shared 30-minute interval |
| Time slot click | PASS — 10:30, 11:00 and 10:00 selected live |
| Date/time sync | PASS — database persisted 2026-10-02 10:30:00 |
| Doctor change revalidation | PASS — Saturday cleared switching all-days doctor to weekday doctor |
| Past date block | PASS — UI disabled; backend rejected past datetime |
| Backend weekday validation | PASS — direct production helper invocation rejected Saturday before writes |
| Backend time validation | PASS — same helper rejected 07:00 and 20:00 before writes |
| Modal reset | PASS — Cancel/reopen clears date/time and restores doctor default |
| Edit Appointment | PASS — loaded saved 10:30, saved unchanged, database confirmed |
| Revisit shared logic | PASS — shared calendar selected 5 October 10:00; cancelled; empty submit blocked |
| Missing date/time | PASS — Revisit and Patients missing-time submissions rejected in UI |
| Remove appointment | PASS — ordinary Save Patient available again |
| Booked slot | PASS — 10:30 shown disabled after reload; own slot selectable in Edit |
| PHP syntax | PASS — both pages and workflow.php |
| JavaScript syntax | PASS — node --check appointment-calendar.js |
| Console errors | 0 during tested browser interactions |

Negative backend tests invoked the actual scheduling function with local database fixtures, within rolled-back transactions. They were not raw HTTP forgery tests. Visit count remained unchanged for all negative cases.

## Disposable record created through the live registration form

- Patient: E2E CALENDAR SELECTION, PatientID 50
- VisitID: 46
- VisitReference: VIS000023
- DoctorID: 1 (Mohamed Ali Abid)
- Stored VisitDate: 2026-10-02 10:30:00
- Consultation fee: 5.00; paid: 0.00; due: 5.00
- Edit test saved the same values. No payment recorded. Fixture retained.

## Files changed in this pass

- auth/assets/appointment-calendar.js — shared scheduler
- auth/assets/clinic.css — selected/today/disabled styling
- auth/pages/reception.php — integration; removed conflicting date conversion and duplicate calendars
- auth/pages/patients.php — integration
- auth/includes/workflow.php — reject explicitly timed appointments in the past
- scripts/calendar_backend_check.php — local negative checks and saved fixture inspection
- CALENDAR_SELECTION_VERIFICATION.md — this report
