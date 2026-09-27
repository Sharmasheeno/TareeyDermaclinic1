-- Visit-level consultation waiver. Run once on existing installations.
ALTER TABLE visits
    ADD COLUMN IsFreeConsultation TINYINT(1) NOT NULL DEFAULT 0 AFTER ConsultationFee;
