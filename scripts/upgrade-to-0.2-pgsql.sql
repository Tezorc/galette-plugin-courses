-- Migration PostgreSQL du schema du plugin vers la version 0.2.
--
-- Equivalent de `upgrade-to-0.2-mysql.sql` ; voir ce fichier pour le detail des
-- phases couvertes et les limites (installations sans ligne dans
-- `galette_plugins`, a traiter avec `manual-catchup-0.2-pgsql.sql`).

-- Desinscription en un clic : jeton par adherent
ALTER TABLE galette_courses_member_preferences
    ADD COLUMN unsubscribe_token varchar(48) DEFAULT NULL;
ALTER TABLE galette_courses_member_preferences
    ADD CONSTRAINT uk_courses_mp_token UNIQUE (unsubscribe_token);

-- Phase 36 : file d'attente des notifications groupees (digests)
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

-- Phase 40 : inscription autorisee avant qu'un moniteur ne se soit positionne
ALTER TABLE galette_courses_events
    ADD COLUMN allow_registration_without_instructor boolean NOT NULL DEFAULT false;

-- Phase 45 : `unregister_deadline_days` -> `register_deadline_days`
ALTER TABLE galette_courses_events
    RENAME COLUMN unregister_deadline_days TO register_deadline_days;

-- Phase 75 : evenement ne necessitant aucun moniteur (l'organisateur est le contact)
ALTER TABLE galette_courses_events
    ADD COLUMN no_instructor_needed boolean NOT NULL DEFAULT false;

-- Creation des seances differee a la validation de l'evenement
ALTER TABLE galette_courses_events
    ADD COLUMN initial_session_date date DEFAULT NULL;

-- Phase 78 : desactivation d'une plage horaire sans suppression (saisonnalite)
ALTER TABLE galette_courses_slots
    ADD COLUMN is_active smallint NOT NULL DEFAULT 1;

-- i18n des motifs d'annulation : cles francaises -> cles anglaises neutres
UPDATE galette_courses_sessions SET cancellation_reason = 'competition'       WHERE cancellation_reason = 'concours';
UPDATE galette_courses_sessions SET cancellation_reason = 'instructor_absent' WHERE cancellation_reason = 'absence_moniteur';
UPDATE galette_courses_sessions SET cancellation_reason = 'training'          WHERE cancellation_reason = 'formation';
UPDATE galette_courses_sessions SET cancellation_reason = 'weather'           WHERE cancellation_reason = 'meteo';
UPDATE galette_courses_sessions SET cancellation_reason = 'other'             WHERE cancellation_reason = 'autre';

-- Phase 74 : indexes sur les chemins chauds
CREATE INDEX IF NOT EXISTS idx_courses_si_member
    ON galette_courses_session_instructors (member_id);
CREATE INDEX IF NOT EXISTS idx_courses_pn_ref
    ON galette_courses_pending_notifications (ref);
