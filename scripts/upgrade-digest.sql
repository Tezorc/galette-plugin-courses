-- Upgrade script: add pending_notifications queue (Phase 36 daily digest)
-- Run once on existing installations that already have the plugin installed.

CREATE TABLE IF NOT EXISTS galette_courses_pending_notifications (
    id_pending int(10) unsigned NOT NULL auto_increment,
    member_id int(10) unsigned NOT NULL,
    event_id int(10) unsigned NOT NULL,
    session_id int(10) unsigned NOT NULL,
    ref varchar(30) NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id_pending),
    UNIQUE KEY uk_courses_pn_member_session_ref (member_id, session_id, ref),
    KEY idx_courses_pn_member (member_id),
    CONSTRAINT fk_courses_pn_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
