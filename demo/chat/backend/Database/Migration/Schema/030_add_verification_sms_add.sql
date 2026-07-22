-- Adds the 'sms_add' verification type for attaching an SMS identity from the
-- profile (HIL-403). Extends the hilos_user_verification `type` ENUM (created in
-- 025, last widened in 027) so the profile add-phone flow can issue and verify a
-- code on a normalized E.164 identifier whose challenge carries the signed-in
-- user id; mirrors the framework stub create_hilos_user_verification.sql.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add') NOT NULL;
