-- Horaires saisonniers : plage de validite sur les creneaux (slots)
--
-- Complete le drapeau is_active (Phase 78) : un creneau peut desormais porter
-- une periode de validite. Le generateur de seances retient, pour chaque date
-- d'occurrence, les creneaux actifs dont la fenetre couvre cette date. Les deux
-- colonnes sont nullables : NULL = pas de borne, donc creneau valable en
-- permanence -> comportement inchange sur les installations existantes.

ALTER TABLE galette_courses_slots
    ADD COLUMN valid_from DATE DEFAULT NULL AFTER is_active,
    ADD COLUMN valid_to DATE DEFAULT NULL AFTER valid_from;
