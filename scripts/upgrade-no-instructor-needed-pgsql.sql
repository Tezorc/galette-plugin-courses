-- Upgrade script (PostgreSQL): per-event toggle "no instructor needed" (Phase 75).
-- Default false keeps current behavior (an instructor may be assigned to sessions of this event).
-- Set to true to declare that this event never needs an instructor; the event creator
-- (organizer) is displayed as the point of contact, and the volunteer/assignment UI is hidden.
--
-- For MySQL/MariaDB installs, use upgrade-no-instructor-needed.sql instead.

ALTER TABLE galette_courses_events
    ADD COLUMN no_instructor_needed boolean NOT NULL DEFAULT false;
