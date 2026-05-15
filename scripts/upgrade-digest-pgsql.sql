-- Upgrade script (PostgreSQL): add pending_notifications queue (Phase 36 daily digest)
-- Run once on existing PostgreSQL installations that already have the plugin installed.
-- For MySQL/MariaDB installs, use upgrade-digest.sql instead.
--
-- Phase 59 reuse: the same table accepts the member-targeted refs
-- ('instructor_assigned', 'session_open') swept weekly by sendWeeklyDigestMember.

CREATE TABLE IF NOT EXISTS galette_courses_pending_notifications (
    id_pending serial PRIMARY KEY,
    member_id integer NOT NULL,
    event_id integer NOT NULL,
    session_id integer NOT NULL,
    ref varchar(30) NOT NULL,
    created_at timestamp NOT NULL,
    CONSTRAINT uk_courses_pn_member_session_ref UNIQUE (member_id, session_id, ref),
    CONSTRAINT fk_courses_pn_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_courses_pn_member ON galette_courses_pending_notifications (member_id);
