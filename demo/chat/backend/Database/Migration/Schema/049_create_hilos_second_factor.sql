-- Activates the second factor (HIL-494) for demo/chat: the authenticator apps a person
-- enrolled, their one-shot backup codes, the browsers trusted to skip the step, the delayed
-- removals, and each person's own removal wait. Copied from the framework stubs
-- create_hilos_second_factor*.sql, where every table explains itself.
--
-- The session row learns to hold a proven sign-in that still owes its second factor, which
-- mirrors the framework stub create_hilos_session.sql. No backfill: a sign-in in flight when
-- this runs was let in the way it always was.

CREATE TABLE `hilos_second_factor` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `label` VARCHAR(64) NOT NULL,
    `secret` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    `last_used_step` BIGINT UNSIGNED DEFAULT NULL,
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_second_factor_backup_code` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_backup_code_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_second_factor_trust` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `trusted_until` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_second_factor_trust` (`session_id`, `user_id`),
    KEY `idx_second_factor_trust_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_second_factor_reset` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `effective_at` TIMESTAMP NOT NULL,
    `cancel_token_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `notified_at` TIMESTAMP NOT NULL,
    `canceled_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_reset_effective` (`effective_at`),
    KEY `idx_second_factor_reset_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_second_factor_setting` (
    `user_id` INT UNSIGNED NOT NULL,
    `reset_wait_days` SMALLINT UNSIGNED DEFAULT NULL,
    `pending_reset_wait_days` SMALLINT UNSIGNED DEFAULT NULL,
    `pending_reset_wait_from` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `hilos_session`
    ADD COLUMN `pending_second_factor_user_id` INT UNSIGNED DEFAULT NULL AFTER `pending_ack`,
    ADD COLUMN `pending_second_factor_mode` VARCHAR(16) DEFAULT NULL AFTER `pending_second_factor_user_id`,
    ADD COLUMN `pending_second_factor_until` TIMESTAMP NULL DEFAULT NULL AFTER `pending_second_factor_mode`,
    ADD COLUMN `pending_second_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pending_second_factor_until`,
    ADD COLUMN `pending_second_factor_ack` VARCHAR(64) DEFAULT NULL AFTER `pending_second_factor_attempts`,
    ADD KEY `idx_session_pending_second_factor` (`pending_second_factor_user_id`);
