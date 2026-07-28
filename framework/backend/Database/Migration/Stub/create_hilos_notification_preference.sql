-- Migration: Create hilos_notification_preference table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_notification_preference.sql)
--
-- Per-user notification channel preferences (HIL-485). The per-user layer above the
-- global channel configuration (HIL-200): a recipient may opt out of an individual
-- delivery channel (email, telegram, sms, push).
--
-- Sparse opt-out model: a row exists ONLY to record a deviation from the default.
-- No row for a (user, channel) pair means the channel is allowed; a row means the
-- recipient muted that channel. Enabling a channel deletes the row (return to
-- default) rather than storing `enabled = 1`, so "allowed" always means the same
-- thing and the table never needs a row per user x channel on registration, nor a
-- rewrite when a new channel (HIL-199 push, HIL-285 sms) is added.
--
-- A mandatory notification type (NotificationTypeRegistry) bypasses this table and
-- delivers on every globally enabled channel where the recipient has an address.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. `user_id` is a soft ref; the per-user
-- lookup rides the leftmost prefix of the UNIQUE(user_id, channel) index, so no
-- separate user_id key is needed. Preference rows are cleaned up best-effort with
-- the user (soft ref, no cascade).

CREATE TABLE `hilos_notification_preference` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(50) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_preference_user_channel` (`user_id`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
