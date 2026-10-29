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

-- Migration MySQL/MariaDB du schema du plugin vers la version 0.5.
--
-- Convention Galette : `scripts/upgrade-to-<version>-<db_type>.sql`, joue
-- automatiquement lorsqu'une installation dont la version enregistree dans
-- `galette_plugins` est inferieure a 0.5 est mise a jour
-- (cf. Install::getUpdateScripts()).
--
-- Nouvelle table des disponibilites des moniteurs : periodes de jours
-- entiers, disponible ou absent, declarees depuis Mes seances moniteur et
-- montrees aux responsables de groupe sur la fiche d'une seance.
--
-- IF NOT EXISTS rend le script rejouable sans erreur.

-- Instructor availability periods (dbver 0.5):
-- whole days, bounds included, kind = 'available' or 'absent' (absent wins
-- when periods overlap). Informs the group managers looking for an
-- instructor, never blocks anything.
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
