-- Creates operation-level step-up confirmations and adds their two verification-code
-- types (HIL-495). The confirmation table mirrors the framework migration stub.
--
-- The ENUM members are appended at the end: that is the only widening MySQL performs
-- in place, and the framework stub describes the same order as a migrated database.

CREATE TABLE `hilos_step_up` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `operation` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `confirmed_until` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_step_up` (`session_token_hash`, `user_id`, `operation`),
    KEY `idx_step_up_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code', 'email_change_current', 'step_up', 'step_up_sms') NOT NULL;
