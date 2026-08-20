-- Rattrapage manuel du schema vers la version 0.2 — PostgreSQL.
--
-- Equivalent de `manual-catchup-0.2-mysql.sql` : voir ce fichier pour l'explication
-- complete du probleme (installations sans ligne dans `galette_plugins`, marquees
-- 0.2 d'office par Plugins::autoMigratePluginVersion() sans qu'aucune migration ne
-- soit jouee, d'ou des erreurs "column does not exist" a la premiere ecriture).
--
-- Idempotent : peut etre lance sans risque sur une base deja a jour.
--
-- Utilisation :
--     psql -U <user> -d <base> -f manual-catchup-0.2-pgsql.sql
--
-- FAIRE UNE SAUVEGARDE AVANT.
--
-- Le prefixe `galette_` est ecrit en dur : si l'installation utilise un autre
-- PREFIX_DB, remplacer `galette_` partout avant execution.
--
-- PostgreSQL supporte ADD COLUMN IF NOT EXISTS et CREATE INDEX IF NOT EXISTS,
-- ce qui rend ce script bien plus direct que son equivalent MySQL. Seuls le
-- renommage de colonne et l'ajout de contrainte demandent un bloc DO.
-- A executer avec psql (les blocs DO $$ ... $$ ne survivent pas a un outil qui
-- decoupe naivement sur les points-virgules).

-- ---------------------------------------------------------------------------
-- 1. Desinscription en un clic : jeton par adherent
-- ---------------------------------------------------------------------------
ALTER TABLE galette_courses_member_preferences
    ADD COLUMN IF NOT EXISTS unsubscribe_token varchar(48) DEFAULT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uk_courses_mp_token'
    ) THEN
        ALTER TABLE galette_courses_member_preferences
            ADD CONSTRAINT uk_courses_mp_token UNIQUE (unsubscribe_token);
    END IF;
END
$$;

-- ---------------------------------------------------------------------------
-- 2. Phase 36 : file d'attente des notifications groupees (digests)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS galette_courses_pending_notifications (
    id_pending serial PRIMARY KEY,
    member_id integer NOT NULL,
    event_id integer NOT NULL,
    session_id integer NOT NULL,
    ref varchar(30) NOT NULL,
    created_at timestamp NOT NULL,
    CONSTRAINT uk_courses_pn_member_session_ref UNIQUE (member_id, session_id, ref),
    CONSTRAINT fk_courses_pn_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_courses_pn_member ON galette_courses_pending_notifications (member_id);

-- ---------------------------------------------------------------------------
-- 3. Phase 40 : inscription autorisee sans moniteur positionne
-- ---------------------------------------------------------------------------
ALTER TABLE galette_courses_events
    ADD COLUMN IF NOT EXISTS allow_registration_without_instructor boolean NOT NULL DEFAULT false;

-- ---------------------------------------------------------------------------
-- 4. Phase 45 : `unregister_deadline_days` -> `register_deadline_days`
--    Renomme uniquement si l'ancienne colonne existe encore ET que la nouvelle
--    est absente.
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'galette_courses_events'
          AND column_name = 'unregister_deadline_days'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'galette_courses_events'
          AND column_name = 'register_deadline_days'
    ) THEN
        ALTER TABLE galette_courses_events
            RENAME COLUMN unregister_deadline_days TO register_deadline_days;
    END IF;
END
$$;

-- ---------------------------------------------------------------------------
-- 5. Phase 75 : evenement ne necessitant aucun moniteur
-- ---------------------------------------------------------------------------
ALTER TABLE galette_courses_events
    ADD COLUMN IF NOT EXISTS no_instructor_needed boolean NOT NULL DEFAULT false;

-- ---------------------------------------------------------------------------
-- 6. Creation des seances differee a la validation de l'evenement
-- ---------------------------------------------------------------------------
ALTER TABLE galette_courses_events
    ADD COLUMN IF NOT EXISTS initial_session_date date DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 7. Phase 78 : desactivation d'une plage horaire sans suppression
-- ---------------------------------------------------------------------------
ALTER TABLE galette_courses_slots
    ADD COLUMN IF NOT EXISTS is_active smallint NOT NULL DEFAULT 1;

-- ---------------------------------------------------------------------------
-- 8. i18n des motifs d'annulation (idempotent par construction)
-- ---------------------------------------------------------------------------
UPDATE galette_courses_sessions SET cancellation_reason = 'competition'       WHERE cancellation_reason = 'concours';
UPDATE galette_courses_sessions SET cancellation_reason = 'instructor_absent' WHERE cancellation_reason = 'absence_moniteur';
UPDATE galette_courses_sessions SET cancellation_reason = 'training'          WHERE cancellation_reason = 'formation';
UPDATE galette_courses_sessions SET cancellation_reason = 'weather'           WHERE cancellation_reason = 'meteo';
UPDATE galette_courses_sessions SET cancellation_reason = 'other'             WHERE cancellation_reason = 'autre';

-- ---------------------------------------------------------------------------
-- 9. Phase 74 : indexes sur les chemins chauds
-- ---------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_courses_si_member
    ON galette_courses_session_instructors (member_id);
CREATE INDEX IF NOT EXISTS idx_courses_pn_ref
    ON galette_courses_pending_notifications (ref);

-- ---------------------------------------------------------------------------
-- 10. Aligner la version de suivi, si la ligne existe deja.
--     Si elle est absente, Galette l'inserera lui-meme au prochain chargement.
--     L'existence de `galette_plugins` est testee au prealable : cette table du
--     coeur est toujours presente sur une installation Galette reelle, mais le
--     script doit rester executable sur une base partielle sans sortir en erreur.
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF to_regclass('galette_plugins') IS NOT NULL THEN
        UPDATE galette_plugins SET version = 0.2 WHERE plugin_id = 'courses';
    END IF;
END
$$;
