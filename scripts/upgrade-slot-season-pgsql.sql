-- Horaires saisonniers : plage de validite sur les creneaux (slots) - PostgreSQL
--
-- Equivalent pgsql de upgrade-slot-season.sql. Les deux colonnes sont
-- nullables : NULL = pas de borne, donc creneau valable en permanence.

ALTER TABLE galette_courses_slots
    ADD COLUMN valid_from date DEFAULT NULL;
ALTER TABLE galette_courses_slots
    ADD COLUMN valid_to date DEFAULT NULL;
