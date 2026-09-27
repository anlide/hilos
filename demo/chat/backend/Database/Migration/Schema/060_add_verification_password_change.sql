-- Adds the email and SMS codes for changing an existing password (HIL-300).
-- Append ENUM members so the widening preserves every existing member ordinal.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code', 'email_change_current', 'step_up', 'step_up_sms', 'account_deletion', 'account_deletion_sms', 'password_change', 'password_change_sms') NOT NULL;
