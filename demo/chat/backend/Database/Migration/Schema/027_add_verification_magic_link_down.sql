-- Reverts 027: drops 'magic_link' from the hilos_user_verification `type` ENUM.
-- Any magic_link rows must be cleared before rollback or the MODIFY truncates them.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login') NOT NULL;
