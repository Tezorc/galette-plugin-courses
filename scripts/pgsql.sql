-- Schema for Galette Courses plugin (PostgreSQL)

DROP TABLE IF EXISTS galette_courses_pending_notifications CASCADE;
DROP TABLE IF EXISTS galette_courses_mail_templates CASCADE;
DROP TABLE IF EXISTS galette_courses_member_preferences CASCADE;
DROP TABLE IF EXISTS galette_courses_preferences CASCADE;
DROP TABLE IF EXISTS galette_courses_waitlist CASCADE;
DROP TABLE IF EXISTS galette_courses_registrations CASCADE;
DROP TABLE IF EXISTS galette_courses_session_instructors CASCADE;
DROP TABLE IF EXISTS galette_courses_sessions CASCADE;
DROP TABLE IF EXISTS galette_courses_slots CASCADE;
DROP TABLE IF EXISTS galette_courses_events_groups CASCADE;
DROP TABLE IF EXISTS galette_courses_events CASCADE;
DROP TABLE IF EXISTS galette_courses_types CASCADE;

CREATE TABLE galette_courses_types (
    id_type serial PRIMARY KEY,
    label varchar(255) NOT NULL
);

INSERT INTO galette_courses_types (label) VALUES
('Cours'),
('Entraînement'),
('Compétition'),
('Découverte'),
('Formation'),
('Stage'),
('Autre');

CREATE TABLE galette_courses_events (
    id_event serial PRIMARY KEY,
    name varchar(255) NOT NULL,
    description text DEFAULT NULL,
    type_id integer NOT NULL,
    location varchar(255) DEFAULT NULL,
    max_capacity integer DEFAULT NULL,
    price numeric(10,2) DEFAULT NULL,
    is_free boolean NOT NULL DEFAULT true,
    is_recurring boolean NOT NULL DEFAULT false,
    recurrence_type varchar(50) DEFAULT NULL,
    recurrence_interval integer DEFAULT NULL,
    recurrence_end_date date DEFAULT NULL,
    advance_weeks integer DEFAULT 4,
    is_restricted boolean NOT NULL DEFAULT false,
    allow_registration_without_instructor boolean NOT NULL DEFAULT false,
    status varchar(20) NOT NULL DEFAULT 'draft',
    register_deadline_days integer DEFAULT NULL,
    creator_id integer DEFAULT NULL,
    creation_date timestamp NOT NULL,
    modification_date timestamp DEFAULT NULL,
    CONSTRAINT fk_courses_events_type FOREIGN KEY (type_id) REFERENCES galette_courses_types (id_type),
    CONSTRAINT fk_courses_events_creator FOREIGN KEY (creator_id) REFERENCES galette_adherents (id_adh)
);
CREATE INDEX idx_courses_events_type ON galette_courses_events (type_id);
CREATE INDEX idx_courses_events_status ON galette_courses_events (status);
CREATE INDEX idx_courses_events_creator ON galette_courses_events (creator_id);

CREATE TABLE galette_courses_events_groups (
    id_event_group serial PRIMARY KEY,
    event_id integer NOT NULL,
    group_id integer NOT NULL,
    CONSTRAINT uk_courses_event_group UNIQUE (event_id, group_id),
    CONSTRAINT fk_courses_eg_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_eg_group FOREIGN KEY (group_id) REFERENCES galette_groups (id_group) ON DELETE CASCADE
);

CREATE TABLE galette_courses_slots (
    id_slot serial PRIMARY KEY,
    event_id integer NOT NULL,
    start_time time NOT NULL,
    end_time time NOT NULL,
    CONSTRAINT fk_courses_slots_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE
);
CREATE INDEX idx_courses_slots_event ON galette_courses_slots (event_id);

CREATE TABLE galette_courses_sessions (
    id_session serial PRIMARY KEY,
    event_id integer NOT NULL,
    session_date date NOT NULL,
    start_time time NOT NULL,
    end_time time NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'open',
    max_capacity integer DEFAULT NULL,
    current_registrations integer NOT NULL DEFAULT 0,
    cancellation_reason varchar(100) DEFAULT NULL,
    cancellation_comment text DEFAULT NULL,
    CONSTRAINT fk_courses_sessions_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE
);
CREATE INDEX idx_courses_sessions_event ON galette_courses_sessions (event_id);
CREATE INDEX idx_courses_sessions_date ON galette_courses_sessions (session_date);
CREATE INDEX idx_courses_sessions_status ON galette_courses_sessions (status);

CREATE TABLE galette_courses_session_instructors (
    id_instructor serial PRIMARY KEY,
    session_id integer NOT NULL,
    member_id integer NOT NULL,
    assigned_date timestamp NOT NULL,
    assigned_by integer DEFAULT NULL,
    CONSTRAINT uk_courses_si_session_member UNIQUE (session_id, member_id),
    CONSTRAINT fk_courses_si_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_si_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh),
    CONSTRAINT fk_courses_si_assigned_by FOREIGN KEY (assigned_by) REFERENCES galette_adherents (id_adh)
);
CREATE INDEX idx_courses_si_session ON galette_courses_session_instructors (session_id);

CREATE TABLE galette_courses_registrations (
    id_registration serial PRIMARY KEY,
    session_id integer NOT NULL,
    member_id integer NOT NULL,
    registration_date timestamp NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'registered',
    registered_by integer DEFAULT NULL,
    CONSTRAINT uk_courses_reg_session_member UNIQUE (session_id, member_id),
    CONSTRAINT fk_courses_reg_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_reg_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh),
    CONSTRAINT fk_courses_reg_registered_by FOREIGN KEY (registered_by) REFERENCES galette_adherents (id_adh)
);
CREATE INDEX idx_courses_reg_member ON galette_courses_registrations (member_id);
CREATE INDEX idx_courses_reg_status ON galette_courses_registrations (status);

CREATE TABLE galette_courses_waitlist (
    id_waitlist serial PRIMARY KEY,
    session_id integer NOT NULL,
    member_id integer NOT NULL,
    position integer NOT NULL,
    added_date timestamp NOT NULL,
    CONSTRAINT uk_courses_wl_session_member UNIQUE (session_id, member_id),
    CONSTRAINT fk_courses_wl_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_wl_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh)
);
CREATE INDEX idx_courses_wl_position ON galette_courses_waitlist (session_id, position);

CREATE TABLE galette_courses_preferences (
    id_pref serial PRIMARY KEY,
    pref_name varchar(100) NOT NULL,
    pref_value varchar(255) NOT NULL DEFAULT '',
    CONSTRAINT uk_courses_pref_name UNIQUE (pref_name)
);

CREATE TABLE galette_courses_mail_templates (
    id_tpl serial PRIMARY KEY,
    tref varchar(30) NOT NULL,
    tsubject text NOT NULL,
    tbody text NOT NULL,
    tlang varchar(10) NOT NULL DEFAULT 'fr_FR',
    CONSTRAINT uk_courses_tpl_ref_lang UNIQUE (tref, tlang)
);

CREATE TABLE galette_courses_member_preferences (
    id_member_pref serial PRIMARY KEY,
    member_id integer NOT NULL,
    notifications_enabled boolean NOT NULL DEFAULT false,
    unsubscribe_token varchar(48) DEFAULT NULL,
    CONSTRAINT uk_courses_mp_member UNIQUE (member_id),
    CONSTRAINT uk_courses_mp_token UNIQUE (unsubscribe_token),
    CONSTRAINT fk_courses_mp_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE
);

-- Notifications queue (Phase 36 + Phase 59):
-- Rows enqueued by notifyNewSessions (managers), notifyInstructorAssigned and
-- notifySessionOpenWithoutInstructor (members). Swept by:
--   - sendDailyDigest() — ref = 'new_sessions_manager' (1 mail/day per manager)
--   - sendWeeklyDigestMember() — ref IN ('instructor_assigned', 'session_open')
--     (1 mail/week per household, parents + children grouped together)
CREATE TABLE galette_courses_pending_notifications (
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
CREATE INDEX idx_courses_pn_member ON galette_courses_pending_notifications (member_id);
