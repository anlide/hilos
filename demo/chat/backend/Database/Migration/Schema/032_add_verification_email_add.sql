-- Adds the 'email_add' verification type for adding a password to an account with
-- no verified email (HIL-406). Extends the hilos_user_verification `type` ENUM
-- (created in 025, last widened in 030) so the profile add-password flow can issue
-- and verify a code on a normalized email identifier whose challenge carries the
-- signed-in user id; mirrors the framework stub create_hilos_user_verification.sql.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add') NOT NULL;
