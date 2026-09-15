<?php
declare(strict_types=1);

/**
 * auth/includes/patient-age.php
 * ---------------------------------------------------------------------
 * Shared patient Age <-> Date-of-Birth helpers.
 *
 * Age is the PRIMARY input for patient registration: when the desk
 * enters an age, the date of birth is derived automatically. Age alone
 * cannot mathematically determine an exact birthday, so the derivation
 * uses one documented, deterministic rule:
 *
 *     DOB = today's month/day, shifted back by the entered years
 *     (e.g. today 2026-09-15, age 56 -> 1970-09-15)
 *
 * DOB stays manually editable. When a valid DOB is supplied it wins and
 * Age is recomputed from it, which keeps the pair consistent and leaves
 * existing historical DOB values untouched.
 *
 * Every declaration is guarded with function_exists() so this file can be
 * included from any page that already declares its own date helpers.
 */

if (!function_exists('tdc_patient_max_age')) {
    /** Upper bound accepted for a patient age, in years. */
    function tdc_patient_max_age(): int
    {
        return 150;
    }
}

if (!function_exists('tdc_is_valid_date_strict')) {
    /** Strict Y-m-d validator (rejects "2026-02-31" style overflow dates). */
    function tdc_is_valid_date_strict(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}

if (!function_exists('tdc_dob_from_age')) {
    /**
     * Deterministic approximate date of birth for an age.
     *
     * @return string Y-m-d date, or '' when the age is unusable.
     */
    function tdc_dob_from_age(int $years, ?string $today = null): string
    {
        if ($years < 0 || $years > tdc_patient_max_age()) {
            return '';
        }

        $today = $today ?? date('Y-m-d');
        $base  = DateTimeImmutable::createFromFormat('Y-m-d', $today);

        if ($base === false || $base->format('Y-m-d') !== $today) {
            return '';
        }

        $dob = $base->modify('-' . $years . ' years')->format('Y-m-d');

        return $dob > $today ? '' : $dob;
    }
}

if (!function_exists('tdc_age_from_dob')) {
    /**
     * Exact age in whole years for a date of birth.
     *
     * Computed with explicit calendar arithmetic rather than
     * DateInterval::$y, which is off by one across some century-spanning
     * ranges (e.g. 1876-09-15 -> 2026-09-15 reports 149, not 150).
     */
    function tdc_age_from_dob(string $dob, ?string $today = null): int
    {
        $today = $today ?? date('Y-m-d');
        $b = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
        $t = DateTimeImmutable::createFromFormat('Y-m-d', $today);

        if ($b === false || $t === false || $b->format('Y-m-d') !== $dob || $t->format('Y-m-d') !== $today) {
            return -1;
        }

        $age = (int) $t->format('Y') - (int) $b->format('Y');

        if ((int) $t->format('n') < (int) $b->format('n')
            || ((int) $t->format('n') === (int) $b->format('n') && (int) $t->format('j') < (int) $b->format('j'))) {
            $age--;
        }

        return $age;
    }
}

if (!function_exists('tdc_sync_age_dob')) {
    /**
     * Reconcile a submitted Age/DateOfBirth pair.
     *
     * Rules, in order:
     *   1. A usable DOB wins and Age is recomputed from it (keeps an
     *      existing, more precise birthday intact).
     *   2. Otherwise a usable Age derives a DOB.
     *   3. Otherwise both values are passed through untouched so the
     *      normal validation layer can report them.
     *
     * @return array{0:string,1:string} [age, dateOfBirth]
     */
    function tdc_sync_age_dob(string $age, string $dob, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $age   = trim($age);
        $dob   = trim($dob);

        $dobWellFormed = $dob !== ''
            && (function_exists('tdc_is_valid_date') ? tdc_is_valid_date($dob) : tdc_is_valid_date_strict($dob));

        if ($dob !== '' && (!$dobWellFormed || $dob > $today)) {
            return [$age, $dob];
        }

        if ($dobWellFormed) {
            return [(string) tdc_age_from_dob($dob, $today), $dob];
        }

        $ageUsable = $age !== ''
            && ctype_digit($age)
            && (int) $age <= tdc_patient_max_age();

        if ($ageUsable) {
            return [$age, tdc_dob_from_age((int) $age, $today)];
        }

        return [$age, $dob];
    }
}