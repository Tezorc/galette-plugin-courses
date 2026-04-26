-- Upgrade script: add unsubscribe_token to member_preferences
-- Run once on existing installations that already have the plugin installed.

ALTER TABLE galette_courses_member_preferences
    ADD COLUMN unsubscribe_token varchar(48) DEFAULT NULL AFTER notifications_enabled,
    ADD UNIQUE KEY uk_courses_mp_token (unsubscribe_token);
