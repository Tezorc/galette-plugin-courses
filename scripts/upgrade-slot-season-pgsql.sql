-- Horaires saisonniers : periode de saison sur les creneaux (slots) - PostgreSQL
--
-- Equivalent pgsql de upgrade-slot-season.sql. Seuls le jour et le mois sont
-- interpretes (l'annee est ignoree, la bascule se refait chaque annee), et les
-- deux colonnes sont nullables : NULL = pas de borne de ce cote.

ALTER TABLE galette_courses_slots
    ADD COLUMN season_from date DEFAULT NULL;
ALTER TABLE galette_courses_slots
    ADD COLUMN season_to date DEFAULT NULL;
