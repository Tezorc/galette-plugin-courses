-- Preferences : pref_value passe de varchar(255) a text (equivalent pgsql du
-- script upgrade-pref-value-text.sql, voir ce fichier pour le detail).
--
-- Postgres n'a pas de troncature silencieuse : il refuse l'ecriture avec
-- "value too long for type character varying(255)". L'exception est avalee par
-- PluginPreferences::set(), donc les fermetures ne sont simplement jamais
-- enregistrees. Le symptome differe, la cause est la meme.

ALTER TABLE galette_courses_preferences
    ALTER COLUMN pref_value TYPE text;
