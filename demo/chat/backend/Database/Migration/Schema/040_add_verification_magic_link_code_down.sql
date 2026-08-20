-- Reverts 040: drops 'magic_link_code' from the hilos_user_verification `type` ENUM.
-- Any magic_link_code rows must be cleared before rollback or the MODIFY truncates them.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add') NOT NULL;
