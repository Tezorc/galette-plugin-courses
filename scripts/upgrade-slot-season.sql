-- Horaires saisonniers : periode de saison sur les creneaux (slots)
--
-- Complete le drapeau is_active (Phase 78) : un creneau peut desormais porter
-- une periode de saison. Le generateur retient, pour chaque date d'occurrence,
-- les creneaux actifs dont la saison couvre cette date.
--
-- Seuls le JOUR et le MOIS de ces deux colonnes sont interpretes : l'annee est
-- ignoree, la bascule se refait donc automatiquement chaque annee. Le type DATE
-- est conserve pour que le formulaire garde un selecteur de date classique.
-- Les deux colonnes sont nullables : NULL = pas de borne de ce cote, donc un
-- creneau sans saison reste valable en permanence -> comportement inchange sur
-- les installations existantes.

ALTER TABLE galette_courses_slots
    ADD COLUMN season_from DATE DEFAULT NULL AFTER is_active,
    ADD COLUMN season_to DATE DEFAULT NULL AFTER season_from;
