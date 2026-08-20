-- Rattrapage manuel du schema vers la version 0.2 — MySQL / MariaDB.
--
-- POURQUOI CE SCRIPT EXISTE
-- -------------------------
-- Les installations anterieures a l'introduction de `dbver` n'ont aucune ligne
-- dans la table `galette_plugins`. Au premier chargement, Galette les fait passer
-- par Plugins::autoMigratePluginVersion(), qui inscrit la version courante (0.2)
-- SANS jouer la moindre migration : la base est alors declaree a jour alors que
-- son schema ne l'est pas. Les pages en lecture continuent de fonctionner, mais
-- la premiere ecriture echoue avec par exemple :
--
--     SQLSTATE[42S22]: Column not found: 1054
--     Unknown column 'allow_registration_without_instructor' in 'field list'
--
-- Comme la version enregistree vaut deja 0.2, Galette ne proposera jamais de mise
-- a jour : `upgrade-to-0.2-mysql.sql` ne sera pas joue. D'ou ce rattrapage,
-- a appliquer une seule fois, a la main.
--
-- QUAND L'UTILISER
-- ----------------
-- Installation existante mise a jour vers Galette 1.3 + plugin >= 0.2, dont la
-- base n'a jamais recu les anciens scripts `upgrade-*.sql`. En cas de doute :
-- ce script est idempotent, il peut etre lance sans risque sur une base deja
-- a jour (il n'y fera rien).
--
-- COMMENT L'UTILISER
-- ------------------
--     mysql -u <user> -p <base> < manual-catchup-0.2-mysql.sql
--
-- ou par copier-coller dans l'onglet SQL de phpMyAdmin.
--
-- FAIRE UNE SAUVEGARDE AVANT. Le script ne supprime rien, mais c'est la regle.
--
-- PREFIXE DES TABLES
-- ------------------
-- Le prefixe `galette_` est ecrit en dur, comme dans les anciens scripts manuels.
-- Si l'installation utilise un autre PREFIX_DB, remplacer `galette_` par ce
-- prefixe dans tout le fichier avant de l'executer.
--
-- Chaque etape teste information_schema avant d'agir, via PREPARE plutot que des
-- procedures stockees : DELIMITER passe mal dans phpMyAdmin.

-- ---------------------------------------------------------------------------
-- 1. Desinscription en un clic : jeton par adherent
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_member_preferences'
       AND COLUMN_NAME = 'unsubscribe_token') = 0,
    'ALTER TABLE galette_courses_member_preferences
        ADD COLUMN unsubscribe_token varchar(48) DEFAULT NULL AFTER notifications_enabled,
        ADD UNIQUE KEY uk_courses_mp_token (unsubscribe_token)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Phase 36 : file d'attente des notifications groupees (digests)
--    CREATE TABLE IF NOT EXISTS est idempotent par nature.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS galette_courses_pending_notifications (
    id_pending int unsigned NOT NULL auto_increment,
    member_id int unsigned NOT NULL,
    event_id int unsigned NOT NULL,
    session_id int unsigned NOT NULL,
    ref varchar(30) NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id_pending),
    UNIQUE KEY uk_courses_pn_member_session_ref (member_id, session_id, ref),
    KEY idx_courses_pn_member (member_id),
    CONSTRAINT fk_courses_pn_member FOREIGN KEY (member_id) REFERENCES galette_adherents (id_adh) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_event FOREIGN KEY (event_id) REFERENCES galette_courses_events (id_event) ON DELETE CASCADE,
    CONSTRAINT fk_courses_pn_session FOREIGN KEY (session_id) REFERENCES galette_courses_sessions (id_session) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Phase 40 : inscription autorisee sans moniteur positionne
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_events'
       AND COLUMN_NAME = 'allow_registration_without_instructor') = 0,
    'ALTER TABLE galette_courses_events
        ADD COLUMN allow_registration_without_instructor tinyint(1) NOT NULL DEFAULT 0
        AFTER is_restricted',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Phase 45 : `unregister_deadline_days` -> `register_deadline_days`
--    Renomme uniquement si l'ancienne colonne existe encore ET que la nouvelle
--    est absente, pour ne pas ecraser un renommage deja effectue.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_events'
       AND COLUMN_NAME = 'unregister_deadline_days') = 1
    AND
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_events'
       AND COLUMN_NAME = 'register_deadline_days') = 0,
    'ALTER TABLE galette_courses_events
        CHANGE unregister_deadline_days register_deadline_days int unsigned DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Phase 75 : evenement ne necessitant aucun moniteur
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_events'
       AND COLUMN_NAME = 'no_instructor_needed') = 0,
    'ALTER TABLE galette_courses_events
        ADD COLUMN no_instructor_needed tinyint(1) NOT NULL DEFAULT 0
        AFTER allow_registration_without_instructor',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. Creation des seances differee a la validation de l'evenement
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_events'
       AND COLUMN_NAME = 'initial_session_date') = 0,
    'ALTER TABLE galette_courses_events
        ADD COLUMN initial_session_date date DEFAULT NULL
        AFTER no_instructor_needed',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7. Phase 78 : desactivation d'une plage horaire sans suppression
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_slots'
       AND COLUMN_NAME = 'is_active') = 0,
    'ALTER TABLE galette_courses_slots
        ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER end_time',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 8. i18n des motifs d'annulation : cles francaises -> cles anglaises neutres.
--    Idempotent par construction (le WHERE ne matche que l'ancienne valeur).
-- ---------------------------------------------------------------------------
UPDATE galette_courses_sessions SET cancellation_reason = 'competition'       WHERE cancellation_reason = 'concours';
UPDATE galette_courses_sessions SET cancellation_reason = 'instructor_absent' WHERE cancellation_reason = 'absence_moniteur';
UPDATE galette_courses_sessions SET cancellation_reason = 'training'          WHERE cancellation_reason = 'formation';
UPDATE galette_courses_sessions SET cancellation_reason = 'weather'           WHERE cancellation_reason = 'meteo';
UPDATE galette_courses_sessions SET cancellation_reason = 'other'             WHERE cancellation_reason = 'autre';

-- ---------------------------------------------------------------------------
-- 9. Phase 74 : indexes sur les chemins chauds
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_session_instructors'
       AND INDEX_NAME = 'idx_courses_si_member') = 0,
    'ALTER TABLE galette_courses_session_instructors
        ADD INDEX idx_courses_si_member (member_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_courses_pending_notifications'
       AND INDEX_NAME = 'idx_courses_pn_ref') = 0,
    'ALTER TABLE galette_courses_pending_notifications
        ADD INDEX idx_courses_pn_ref (ref)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 10. Aligner la version de suivi, si la ligne existe deja.
--     Si elle est absente, Galette l'inserera lui-meme au prochain chargement.
--     L'existence de `galette_plugins` est testee au prealable : cette table du
--     coeur est toujours presente sur une installation Galette reelle, mais le
--     script doit rester executable sur une base partielle (banc de test,
--     restauration incomplete) sans sortir en erreur sur sa derniere instruction.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'galette_plugins') = 1,
    'UPDATE galette_plugins SET version = 0.2 WHERE plugin_id = ''courses''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
