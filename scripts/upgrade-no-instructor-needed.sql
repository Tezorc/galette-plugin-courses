-- Phase 75: per-event toggle "no instructor needed (organizer is the contact)"
-- Default 0 keeps current behavior (an instructor may be assigned to sessions of this event).
-- Set to 1 to declare that this event never needs an instructor; the event creator
-- (organizer) is displayed as the point of contact, and the volunteer/assignment UI is hidden.

ALTER TABLE galette_courses_events
    ADD COLUMN no_instructor_needed tinyint(1) NOT NULL DEFAULT 0
    AFTER allow_registration_without_instructor;
