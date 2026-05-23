-- Phase 74 (MySQL/MariaDB) : indexes pour accelerer les hot paths.
--
-- 1) session_instructors.member_id : utilise par countSessionsForMember()
--    et getSessionIdsForMember(), appelees a chaque rendu du menu / dashboard
--    membre (verifie si l'entree "Mes seances comme moniteur" doit s'afficher).
--    Sans index, full scan a chaque page. Le UNIQUE composite
--    (session_id, member_id) ne couvre pas WHERE member_id=? (left-most rule).
--
-- 2) pending_notifications.ref : utilise par les sweeps cron
--    (sendDailyDigest, sendWeeklyDigestMember) qui font des
--    MAX(id_pending) WHERE ref=?, SELECT ... WHERE ref IN (...) et
--    DELETE WHERE id_pending <= ? AND ref=?. Sans index, full scan
--    de la queue a chaque execution du cron.

ALTER TABLE galette_courses_session_instructors
    ADD INDEX idx_courses_si_member (member_id);

ALTER TABLE galette_courses_pending_notifications
    ADD INDEX idx_courses_pn_ref (ref);
