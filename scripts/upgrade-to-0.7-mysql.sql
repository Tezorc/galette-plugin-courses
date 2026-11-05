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

-- Migration MySQL/MariaDB du schema du plugin vers la version 0.7.
--
-- Convention Galette : `scripts/upgrade-to-<version>-<db_type>.sql`. Le
-- plugin passe en Need update tant qu'un administrateur n'a pas lance la mise
-- a jour depuis Configuration > Plugins.
--
-- Nouvelle table des notes du planning moniteurs : une note libre par jour,
-- une par moniteur, affichee avec son prenom.
--
-- IF NOT EXISTS rend le script rejouable sans erreur.
--
-- Filet de securite : les tables des disponibilites (0.5) et des
-- sollicitations (0.6) sont recreees si absentes.
-- L'assistant de mise a jour demande la version precedente et ne joue que les
-- scripts au-dessus : choisir 0.5 au lieu de 0.4 sautait upgrade-to-0.5 (vu en
-- prod le 2026-10-31).

-- Instructor availability (dbver 0.5), repeated from upgrade-to-0.5-mysql.sql.
CREATE TABLE IF NOT EXISTS galette_courses_instructor_availability (
    id_availability int unsigned NOT NULL auto_increment,
    member_id int unsigned NOT NULL,
    date_from date NOT NULL,
    date_to date NOT NULL,
    kind varchar(10) NOT NULL,
    comment varchar(255) DEFAULT NULL,
    created_date datetime NOT NULL,
    PRIMARY KEY (id_availability),
    KEY idx_courses_ia_member (member_id),
    KEY idx_courses_ia_dates (date_from, date_to),
    CONSTRAINT fk_courses_ia_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instructor solicitations (dbver 0.6), repeated from upgrade-to-0.6-mysql.sql:
-- a group manager or a session manager asks an instructor to lead a session.
-- status = 'pending', 'accepted', 'declined' or 'cancelled' (the session found
-- another instructor first). One row per (session, instructor), soliciting
-- again resets it to pending.
CREATE TABLE IF NOT EXISTS galette_courses_instructor_solicitations (
    id_solicitation int unsigned NOT NULL auto_increment,
    session_id int unsigned NOT NULL,
    member_id int unsigned NOT NULL,
    requested_by int unsigned DEFAULT NULL,
    status varchar(10) NOT NULL DEFAULT 'pending',
    created_date datetime NOT NULL,
    answered_date datetime DEFAULT NULL,
    PRIMARY KEY (id_solicitation),
    UNIQUE KEY uk_courses_sol_session_member (session_id, member_id),
    KEY idx_courses_sol_member (member_id),
    CONSTRAINT fk_courses_sol_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_sol_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_sol_requested_by FOREIGN KEY (requested_by) REFERENCES galette_adherents (id_adh) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planning notes (dbver 0.7): a free note per day of the instructor planning
-- and per member who writes it, shown with their first name to everyone who
-- sees the planning.
CREATE TABLE IF NOT EXISTS galette_courses_planning_notes (
    note_date date NOT NULL,
    member_id int unsigned NOT NULL,
    note varchar(255) NOT NULL,
    updated_date datetime NOT NULL,
    PRIMARY KEY (note_date, member_id),
    KEY idx_courses_pln_member (member_id),
    CONSTRAINT fk_courses_pln_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
