-- Reverts 056: drops account deletion requests and their verification-code types.
-- Any account_deletion / account_deletion_sms verification rows must be cleared before rollback.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code', 'email_change_current', 'step_up', 'step_up_sms') NOT NULL;

DROP TABLE IF EXISTS `hilos_account_deletion`;
