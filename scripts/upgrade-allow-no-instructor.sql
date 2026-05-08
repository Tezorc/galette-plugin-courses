-- Phase 40: per-event toggle "allow registration without instructor"
-- Default 0 keeps current behavior (registration blocked while no instructor is assigned).
-- Set to 1 on a given event to let members register on its sessions even before an instructor volunteers.

ALTER TABLE galette_courses_events
    ADD COLUMN allow_registration_without_instructor tinyint(1) NOT NULL DEFAULT 0
    AFTER is_restricted;
