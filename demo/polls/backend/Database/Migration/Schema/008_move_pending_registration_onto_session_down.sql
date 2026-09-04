-- Migration (down): Put the pending registration back in hilos_registration_wait
-- Index: 008
-- Description:
--   The CREATE is the text of the framework stub this table was activated from in
--   007, reproduced here because that stub is gone with HIL-612 and a down
--   migration has to stand on its own.

CREATE TABLE `hilos_registration_wait` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registration_wait_session` (`session_token`),
    KEY `idx_registration_wait_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `hilos_registration_wait` (`session_token`, `identifier`, `created_at`)
    SELECT `token`, `pending_registration_identifier`, COALESCE(`pending_registration_since`, CURRENT_TIMESTAMP)
    FROM `hilos_session`
    WHERE `pending_registration_identifier` IS NOT NULL;

ALTER TABLE `hilos_session`
    DROP INDEX `idx_session_pending_registration`,
    DROP COLUMN `pending_registration_since`,
    DROP COLUMN `pending_registration_identifier`;
