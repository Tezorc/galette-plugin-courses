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

-- Migration MySQL/MariaDB du schema du plugin vers la version 0.3.
--
-- Convention Galette : `scripts/upgrade-to-<version>-<db_type>.sql`, joue
-- automatiquement lorsqu'une installation dont la version enregistree dans
-- `galette_plugins` est inferieure a 0.3 est mise a jour
-- (cf. Install::getUpdateScripts()).
--
-- Horaires saisonniers : plage de validite facultative sur chaque creneau. Le
-- generateur retient, pour chaque date d'occurrence, les creneaux actifs dont la
-- fenetre couvre cette date. Les deux colonnes sont nullables : NULL = pas de
-- borne de ce cote, donc un creneau sans fenetre reste valable en permanence et
-- rien ne change pour les installations existantes.
--
-- Note : `executeSql()` decoupe le fichier sur `;` et remplace `galette_` par le
-- prefixe reel de la base. Ne pas utiliser DELIMITER ni de procedure stockee ici.

ALTER TABLE galette_courses_slots
    ADD COLUMN valid_from DATE DEFAULT NULL AFTER is_active,
    ADD COLUMN valid_to DATE DEFAULT NULL AFTER valid_from;
