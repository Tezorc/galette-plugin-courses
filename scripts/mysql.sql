-- Schema for Galette Courses plugin

DROP TABLE IF EXISTS galette_courses_pending_notifications;
DROP TABLE IF EXISTS galette_courses_mail_templates;
DROP TABLE IF EXISTS galette_courses_member_preferences;
DROP TABLE IF EXISTS galette_courses_preferences;
DROP TABLE IF EXISTS galette_courses_waitlist;
DROP TABLE IF EXISTS galette_courses_registrations;
DROP TABLE IF EXISTS galette_courses_session_instructors;
DROP TABLE IF EXISTS galette_courses_sessions;
DROP TABLE IF EXISTS galette_courses_slots;
DROP TABLE IF EXISTS galette_courses_events_groups;
DROP TABLE IF EXISTS galette_courses_events;
DROP TABLE IF EXISTS galette_courses_types;

CREATE TABLE galette_courses_types (
    id_type int(10) unsigned NOT NULL auto_increment,
    label varchar(255) NOT NULL,
    PRIMARY KEY (id_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO galette_courses_types (label) VALUES
('Cours'),
('Entraînement'),
('Compétition'),
('Découverte'),
('Formation'),
('Stage'),
('Autre');

CREATE TABLE galette_courses_events (
    id_event int(10) unsigned NOT NULL auto_increment,
    name varchar(255) NOT NULL,
    description text DEFAULT NULL,
    type_id int(10) unsigned NOT NULL,
    location varchar(255) DEFAULT NULL,
    max_capacity int(10) unsigned DEFAULT NULL,
    price decimal(10,2) DEFAULT NULL,
    is_free tinyint(1) NOT NULL DEFAULT 1,
    is_recurring tinyint(1) NOT NULL DEFAULT 0,
    recurrence_type varchar(50) DEFAULT NULL,
    recurrence_interval int(10) unsigned DEFAULT NULL,
    recurrence_end_date date DEFAULT NULL,
    advance_weeks int(10) unsigned DEFAULT 4,
    is_restricted tinyint(1) NOT NULL DEFAULT 0,
    allow_registration_without_instructor tinyint(1) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'draft',
    register_deadline_days int(10) unsigned DEFAULT NULL,
    creator_id int(10) unsigned DEFAULT NULL,
    creation_date datetime NOT NULL,
    modification_date datetime DEFAULT NULL,
    PRIMARY KEY (id_event),
    KEY idx_courses_events_type (type_id),
    KEY idx_courses_events_status (status),
    KEY idx_courses_events_creator (creator_id),
    CONSTRAINT fk_courses_events_type FOREIGN KEY (type_id) REFERENCES galette_courses_types (id_type),
    CONSTRAINT fk_courses_events_creator FOREIGN KEY (creator_id) REFERENCES galette_adherents (id_adh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_events_groups (
    id_event_group int(10) unsigned NOT NULL auto_increment,
    event_id int(10) unsigned NOT NULL,
    group_id int(10) unsigned NOT NULL,
    PRIMARY KEY (id_event_group),
    UNIQUE KEY uk_courses_event_group (event_id, group_id),
    CONSTRAINT fk_courses_eg_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_eg_group FOREIGN KEY (group_id) REFERENCES galette_groups (id_group) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_slots (
    id_slot int(10) unsigned NOT NULL auto_increment,
    event_id int(10) unsigned NOT NULL,
    start_time time NOT NULL,
    end_time time NOT NULL,
    PRIMARY KEY (id_slot),
    KEY idx_courses_slots_event (event_id),
    CONSTRAINT fk_courses_slots_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_sessions (
    id_session int(10) unsigned NOT NULL auto_increment,
    event_id int(10) unsigned NOT NULL,
    session_date date NOT NULL,
    start_time time NOT NULL,
    end_time time NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'open',
    max_capacity int(10) unsigned DEFAULT NULL,
    current_registrations int(10) unsigned NOT NULL DEFAULT 0,
    cancellation_reason varchar(100) DEFAULT NULL,
    cancellation_comment text DEFAULT NULL,
    PRIMARY KEY (id_session),
    KEY idx_courses_sessions_event (event_id),
    KEY idx_courses_sessions_date (session_date),
    KEY idx_courses_sessions_status (status),
    CONSTRAINT fk_courses_sessions_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_session_instructors (
    id_instructor int(10) unsigned NOT NULL auto_increment,
    session_id int(10) unsigned NOT NULL,
    member_id int(10) unsigned NOT NULL,
    assigned_date datetime NOT NULL,
    assigned_by int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (id_instructor),
    UNIQUE KEY uk_courses_si_session_member (session_id, member_id),
    KEY idx_courses_si_session (session_id),
    CONSTRAINT fk_courses_si_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_si_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh),
    CONSTRAINT fk_courses_si_assigned_by FOREIGN KEY (assigned_by) REFERENCES galette_adherents (id_adh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_registrations (
    id_registration int(10) unsigned NOT NULL auto_increment,
    session_id int(10) unsigned NOT NULL,
    member_id int(10) unsigned NOT NULL,
    registration_date datetime NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'registered',
    registered_by int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (id_registration),
    UNIQUE KEY uk_courses_reg_session_member (session_id, member_id),
    KEY idx_courses_reg_member (member_id),
    KEY idx_courses_reg_status (status),
    CONSTRAINT fk_courses_reg_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_reg_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh),
    CONSTRAINT fk_courses_reg_registered_by FOREIGN KEY (registered_by) REFERENCES galette_adherents (id_adh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_waitlist (
    id_waitlist int(10) unsigned NOT NULL auto_increment,
    session_id int(10) unsigned NOT NULL,
    member_id int(10) unsigned NOT NULL,
    position int(10) unsigned NOT NULL,
    added_date datetime NOT NULL,
    PRIMARY KEY (id_waitlist),
    UNIQUE KEY uk_courses_wl_session_member (session_id, member_id),
    KEY idx_courses_wl_position (session_id, position),
    CONSTRAINT fk_courses_wl_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_wl_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_preferences (
    id_pref int(10) unsigned NOT NULL auto_increment,
    pref_name varchar(100) NOT NULL,
    pref_value varchar(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id_pref),
    UNIQUE KEY uk_courses_pref_name (pref_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_mail_templates (
    id_tpl int(10) unsigned NOT NULL auto_increment,
    tref varchar(30) NOT NULL,
    tsubject text NOT NULL,
    tbody text NOT NULL,
    tlang varchar(10) NOT NULL DEFAULT 'fr_FR',
    PRIMARY KEY (id_tpl),
    UNIQUE KEY uk_courses_tpl_ref_lang (tref, tlang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galette_courses_member_preferences (
    id_member_pref int(10) unsigned NOT NULL auto_increment,
    member_id int(10) unsigned NOT NULL,
    notifications_enabled tinyint(1) NOT NULL DEFAULT 0,
    unsubscribe_token varchar(48) DEFAULT NULL,
    PRIMARY KEY (id_member_pref),
    UNIQUE KEY uk_courses_mp_member (member_id),
    UNIQUE KEY uk_courses_mp_token (unsubscribe_token),
    CONSTRAINT fk_courses_mp_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily-digest queue: rows accumulated by notifyNewSessions during the day,
-- swept and emailed once per day by the cron (one consolidated email per recipient).
-- Note: integer columns omit the legacy display width (`int(10)`) — deprecated in MySQL 8.
CREATE TABLE galette_courses_pending_notifications (
    id_pending int unsigned NOT NULL auto_increment,
    member_id int unsigned NOT NULL,
    event_id int unsigned NOT NULL,
    session_id int unsigned NOT NULL,
    ref varchar(30) NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id_pending),
    UNIQUE KEY uk_courses_pn_member_session_ref (member_id, session_id, ref),
    KEY idx_courses_pn_member (member_id),
    CONSTRAINT fk_courses_pn_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
