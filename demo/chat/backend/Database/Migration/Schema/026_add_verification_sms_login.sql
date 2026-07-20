-- Adds the 'sms_login' verification type for phone one-time-code sign-in (HIL-280).
-- Extends the hilos_user_verification `type` ENUM (created in 025) so the SMS-login
-- flow can issue and verify codes on a normalized E.164 identifier; mirrors the
-- framework stub create_hilos_user_verification.sql.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login') NOT NULL;
