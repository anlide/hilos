-- Adds the 'magic_link' verification type for passwordless email sign-in (HIL-283).
-- Extends the hilos_user_verification `type` ENUM (created in 025, last widened in
-- 026) so the magic-link flow can issue and verify a long URL-safe token on a
-- lowercased-email identifier; mirrors the framework stub
-- create_hilos_user_verification.sql.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link') NOT NULL;
