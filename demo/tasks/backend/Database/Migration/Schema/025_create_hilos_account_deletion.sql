-- Creates people's own account deletion requests and adds the two verification-code types
-- that confirm them (HIL-302). The request table mirrors the framework migration stub.
--
-- The ENUM members are appended at the end: that is the only widening MySQL performs
-- in place, and the framework stub describes the same order as a migrated database.

CREATE TABLE `hilos_account_deletion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `effective_at` TIMESTAMP NOT NULL,
    `canceled_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_account_deletion_effective` (`effective_at`),
    KEY `idx_account_deletion_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code', 'email_change_current', 'step_up', 'step_up_sms', 'account_deletion', 'account_deletion_sms') NOT NULL;
