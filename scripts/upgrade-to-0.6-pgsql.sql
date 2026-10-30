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

-- Migration PostgreSQL du schema du plugin vers la version 0.6.
--
-- Equivalent de `upgrade-to-0.6-mysql.sql` : nouvelle table des
-- sollicitations de moniteurs.

-- Instructor solicitations (dbver 0.6):
-- a group manager or a session manager asks an instructor to lead a session.
-- status = 'pending', 'accepted', 'declined' or 'cancelled' (the session found
-- another instructor first). One row per (session, instructor), soliciting
-- again resets it to pending.
CREATE TABLE IF NOT EXISTS galette_courses_instructor_solicitations (
    id_solicitation serial PRIMARY KEY,
    session_id integer NOT NULL,
    member_id integer NOT NULL,
    requested_by integer DEFAULT NULL,
    status varchar(10) NOT NULL DEFAULT 'pending',
    created_date timestamp NOT NULL,
    answered_date timestamp DEFAULT NULL,
    CONSTRAINT uk_courses_sol_session_member UNIQUE (session_id, member_id),
    CONSTRAINT fk_courses_sol_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_courses_sol_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_sol_requested_by FOREIGN KEY (requested_by) REFERENCES galette_adherents (id_adh) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_courses_sol_member ON galette_courses_instructor_solicitations (member_id);
