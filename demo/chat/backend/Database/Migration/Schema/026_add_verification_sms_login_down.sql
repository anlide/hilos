-- Reverts 026: drops 'sms_login' from the hilos_user_verification `type` ENUM.
-- Any sms_login rows must be cleared before rollback or the MODIFY truncates them.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change') NOT NULL;
