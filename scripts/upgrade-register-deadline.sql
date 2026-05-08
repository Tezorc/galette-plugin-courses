-- Phase 45: rename `unregister_deadline_days` -> `register_deadline_days`.
-- The semantic flips: the column now represents how many days *before the
-- session* registrations are closed (was: how many days before the session
-- unregistrations were closed). Existing values are preserved unchanged —
-- in practice clubs that configured a 3-day unregister cutoff usually want
-- the same 3-day cutoff on registration. Unregistration is now always
-- allowed up to the session start time.

ALTER TABLE galette_courses_events
    CHANGE unregister_deadline_days register_deadline_days int(10) unsigned DEFAULT NULL;
