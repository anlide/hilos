-- Reverts 030: drops 'sms_add' from the hilos_user_verification `type` ENUM.
-- Any sms_add rows must be cleared before rollback or the MODIFY truncates them.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link') NOT NULL;
