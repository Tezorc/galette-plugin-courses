-- Phase 74 (PostgreSQL) : indexes pour accelerer les hot paths.
-- Voir upgrade-perf-indexes.sql pour le detail.

CREATE INDEX idx_courses_si_member
    ON galette_courses_session_instructors (member_id);

CREATE INDEX idx_courses_pn_ref
    ON galette_courses_pending_notifications (ref);
