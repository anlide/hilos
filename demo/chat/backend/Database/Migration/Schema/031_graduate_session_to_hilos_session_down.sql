-- Migration (down): Revert hilos_session back to the chat-owned session table
-- Index: 031
--
-- Recreates `session` as migrations 021 (base) + 023 (impersonator) left it,
-- moves every row back from hilos_session, then drops hilos_session.

CREATE TABLE `session` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token` VARCHAR(64) NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `impersonator_user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `token` (`token`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `session`
    (`id`, `token`, `user_id`, `impersonator_user_id`, `created_at`, `last_seen_at`, `expires_at`)
SELECT
    `id`, `token`, `user_id`, `impersonator_user_id`, `created_at`, `last_seen_at`, `expires_at`
FROM `hilos_session`;

DROP TABLE `hilos_session`;
