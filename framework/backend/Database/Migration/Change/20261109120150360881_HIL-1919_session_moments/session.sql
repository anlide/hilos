ALTER TABLE `hilos_session`
    ADD COLUMN `__hil1919_created_at` BIGINT NULL AFTER `created_at`,
    ADD COLUMN `__hil1919_last_seen_at` BIGINT NULL AFTER `last_seen_at`,
    ADD COLUMN `__hil1919_expires_at` BIGINT NULL AFTER `expires_at`,
    ADD COLUMN `__hil1919_pending_registration_since` BIGINT NULL AFTER `pending_registration_since`,
    ADD COLUMN `__hil1919_pending_second_factor_until` BIGINT NULL AFTER `pending_second_factor_until`;
UPDATE `hilos_session` SET
    `__hil1919_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000,
    `__hil1919_last_seen_at` = UNIX_TIMESTAMP(`last_seen_at`) * 1000,
    `__hil1919_expires_at` = UNIX_TIMESTAMP(`expires_at`) * 1000,
    `__hil1919_pending_registration_since` = UNIX_TIMESTAMP(`pending_registration_since`) * 1000,
    `__hil1919_pending_second_factor_until` = UNIX_TIMESTAMP(`pending_second_factor_until`) * 1000;
ALTER TABLE `hilos_session`
    DROP INDEX `idx_session_expires`,
    DROP INDEX `idx_session_anonymous_seen`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1919_created_at`,
    DROP COLUMN `last_seen_at`, ADD COLUMN `last_seen_at` BIGINT NOT NULL AFTER `__hil1919_last_seen_at`,
    DROP COLUMN `expires_at`, ADD COLUMN `expires_at` BIGINT NULL DEFAULT NULL AFTER `__hil1919_expires_at`,
    DROP COLUMN `pending_registration_since`, ADD COLUMN `pending_registration_since` BIGINT NULL DEFAULT NULL AFTER `__hil1919_pending_registration_since`,
    DROP COLUMN `pending_second_factor_until`, ADD COLUMN `pending_second_factor_until` BIGINT NULL DEFAULT NULL AFTER `__hil1919_pending_second_factor_until`,
    ADD KEY `idx_session_expires` (`expires_at`),
    ADD KEY `idx_session_anonymous_seen` (`user_id`, `last_seen_at`);
UPDATE `hilos_session` SET
    `created_at` = `__hil1919_created_at`,
    `last_seen_at` = `__hil1919_last_seen_at`,
    `expires_at` = `__hil1919_expires_at`,
    `pending_registration_since` = `__hil1919_pending_registration_since`,
    `pending_second_factor_until` = `__hil1919_pending_second_factor_until`;
ALTER TABLE `hilos_session`
    DROP COLUMN `__hil1919_created_at`,
    DROP COLUMN `__hil1919_last_seen_at`,
    DROP COLUMN `__hil1919_expires_at`,
    DROP COLUMN `__hil1919_pending_registration_since`,
    DROP COLUMN `__hil1919_pending_second_factor_until`;
