-- Phase 78 : ajout du drapeau is_active sur les plages horaires (slots)
-- Permet de desactiver une plage sans la supprimer (cas saisonnier ete/hiver
-- pour les evenements recurrents). Defaut = 1 (actif) -> aucun impact sur
-- les installations existantes.

ALTER TABLE galette_courses_slots
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER end_time;
