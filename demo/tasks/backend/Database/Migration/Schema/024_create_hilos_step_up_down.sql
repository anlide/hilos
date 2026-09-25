-- Reverts 024: drops step-up confirmations and their verification-code types.
-- Any step_up / step_up_sms verification rows must be cleared before rollback.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code', 'email_change_current') NOT NULL;

DROP TABLE IF EXISTS `hilos_step_up`;
