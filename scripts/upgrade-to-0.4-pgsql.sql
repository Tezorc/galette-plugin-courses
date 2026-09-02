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

-- Migration PostgreSQL du schema du plugin vers la version 0.4.
--
-- Equivalent de `upgrade-to-0.4-mysql.sql` : pref_value passe de varchar(255)
-- a text, les periodes de fermeture serialisees en JSON debordant de la colonne
-- des la troisieme periode libellee.
--
-- Postgres ne tronque pas en silence, il refuse l'ecriture ("value too long for
-- type character varying(255)"). L'exception etant avalee par
-- PluginPreferences::set(), les fermetures n'etaient simplement jamais
-- enregistrees. Le symptome differe de MySQL, la cause est la meme.

ALTER TABLE galette_courses_preferences
    ALTER COLUMN pref_value TYPE text;
