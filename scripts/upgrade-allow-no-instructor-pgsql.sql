-- Upgrade script (PostgreSQL): per-event toggle "allow registration without instructor" (Phase 40).
-- Default false keeps current behavior (registration blocked while no instructor is assigned).
-- Set to true on a given event to let members register on its sessions even before an instructor volunteers.
--
-- For MySQL/MariaDB installs, use upgrade-allow-no-instructor.sql instead.

ALTER TABLE galette_courses_events
    ADD COLUMN allow_registration_without_instructor boolean NOT NULL DEFAULT false;
