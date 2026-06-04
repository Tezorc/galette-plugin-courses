-- Upgrade script (PostgreSQL): defer session creation until event validation.
-- Stores the date entered on the create/edit form so that, when the event is
-- validated, sessions can be generated from that date (one-shot or recurring
-- starting point). Nullable because events created before this migration may
-- not have it set — those will fall back to the existing sessions they already
-- have.
--
-- For MySQL/MariaDB installs, use upgrade-defer-sessions.sql instead.

ALTER TABLE galette_courses_events
    ADD COLUMN initial_session_date date DEFAULT NULL;
