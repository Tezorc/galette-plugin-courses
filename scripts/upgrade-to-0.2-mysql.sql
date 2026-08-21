--
-- Copyright © 2026-2026 The Galette Team && The CCAG42 Team
--
-- This file is part of Galette Courses plugin (https://github.com/Tezorc/galette-plugin-courses).
--
-- Galette Courses Plugin is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- Galette Courses Plugin is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
--  GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with Galette Courses Plugin. If not, see <http://www.gnu.org/licenses/>.
--

-- Migration MySQL/MariaDB du schema du plugin vers la version 0.2.
--
-- Ce script suit la convention attendue par Galette
-- (`scripts/upgrade-to-<version>-<db_type>.sql`, cf. Install::getUpdateScripts()) :
-- il est execute automatiquement lorsqu'une installation dont la version de base
-- enregistree dans `galette_plugins` est inferieure a 0.2 est mise a jour.
--
-- Il regroupe les migrations qui etaient jusqu'ici livrees sous forme de scripts
-- `upgrade-*.sql` ad hoc, a appliquer a la main (Phases 36, 40, 45, 74, 75, 78,
-- desinscription et i18n des motifs d'annulation).
--
-- ATTENTION : ce script ne couvre PAS les installations anterieures a
-- l'introduction de `dbver`, qui n'ont aucune ligne dans `galette_plugins`.
-- Celles-la sont marquees 0.2 d'office par Plugins::autoMigratePluginVersion()
-- sans qu'aucune migration ne soit jouee. Pour ces installations, utiliser
-- `manual-catchup-0.2-mysql.sql`, qui est idempotent.
--
-- Note : `executeSql()` decoupe le fichier sur `;` et remplace `galette_` par le
-- prefixe reel de la base. Ne pas utiliser DELIMITER ni de procedure stockee ici.

-- Desinscription en un clic : jeton par adherent
ALTER TABLE galette_courses_member_preferences
    ADD COLUMN unsubscribe_token varchar(48) DEFAULT NULL AFTER notifications_enabled,
    ADD UNIQUE KEY uk_courses_mp_token (unsubscribe_token);

-- Phase 36 : file d'attente des notifications groupees (digests)
CREATE TABLE IF NOT EXISTS galette_courses_pending_notifications (
    id_pending int unsigned NOT NULL auto_increment,
    member_id int unsigned NOT NULL,
    event_id int unsigned NOT NULL,
    session_id int unsigned NOT NULL,
    ref varchar(30) NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id_pending),
    UNIQUE KEY uk_courses_pn_member_session_ref (member_id, session_id, ref),
    KEY idx_courses_pn_member (member_id),
    KEY idx_courses_pn_ref (ref),
    CONSTRAINT fk_courses_pn_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 40 : inscription autorisee avant qu'un moniteur ne se soit positionne
ALTER TABLE galette_courses_events
    ADD COLUMN allow_registration_without_instructor tinyint(1) NOT NULL DEFAULT 0
    AFTER is_restricted;

-- Phase 45 : `unregister_deadline_days` -> `register_deadline_days`
-- La semantique bascule : delai de fermeture des INSCRIPTIONS avant la seance.
-- Les valeurs existantes sont conservees telles quelles.
ALTER TABLE galette_courses_events
    CHANGE unregister_deadline_days register_deadline_days int unsigned DEFAULT NULL;

-- Phase 75 : evenement ne necessitant aucun moniteur (l'organisateur est le contact)
ALTER TABLE galette_courses_events
    ADD COLUMN no_instructor_needed tinyint(1) NOT NULL DEFAULT 0
    AFTER allow_registration_without_instructor;

-- Creation des seances differee a la validation de l'evenement
ALTER TABLE galette_courses_events
    ADD COLUMN initial_session_date date DEFAULT NULL
    AFTER no_instructor_needed;

-- Phase 78 : desactivation d'une plage horaire sans suppression (saisonnalite)
ALTER TABLE galette_courses_slots
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER end_time;

-- i18n des motifs d'annulation : cles francaises -> cles anglaises neutres
UPDATE galette_courses_sessions SET cancellation_reason = 'competition'       WHERE cancellation_reason = 'concours';
UPDATE galette_courses_sessions SET cancellation_reason = 'instructor_absent' WHERE cancellation_reason = 'absence_moniteur';
UPDATE galette_courses_sessions SET cancellation_reason = 'training'          WHERE cancellation_reason = 'formation';
UPDATE galette_courses_sessions SET cancellation_reason = 'weather'           WHERE cancellation_reason = 'meteo';
UPDATE galette_courses_sessions SET cancellation_reason = 'other'             WHERE cancellation_reason = 'autre';

-- Phase 74 : index sur session_instructors.member_id.
-- Le UNIQUE (session_id, member_id) ne couvre pas WHERE member_id=? (regle du
-- prefixe le plus a gauche), or cette requete est jouee a chaque rendu du menu
-- membre. L'index sur pending_notifications.ref, lui, est deja pose ci-dessus
-- dans le CREATE TABLE.
--
-- Cet index fait partie du schema initial (`mysql.sql`) depuis l'integration de
-- la Phase 74 : il est donc deja present sur les installations postericures a
-- celle-ci, et absent sur les plus anciennes. MySQL n'ayant pas de
-- `ADD INDEX IF NOT EXISTS`, on teste information_schema avant d'agir, sans quoi
-- le script echouerait sur "Duplicate key name".
-- Le nom de table est ecrit litteralement : executeSql() fait un str_replace()
-- textuel de `galette_` vers le prefixe reel sur l'ensemble du fichier, chaines
-- quotees comprises.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_session_instructors'
       AND INDEX_NAME = 'idx_courses_si_member') = 0,
    'ALTER TABLE galette_courses_session_instructors ADD INDEX idx_courses_si_member (member_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
