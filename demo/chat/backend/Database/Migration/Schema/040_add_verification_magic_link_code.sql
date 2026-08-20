-- Adds the 'magic_link_code' verification type for the code that rides in the
-- magic-link letter (HIL-606). Extends the hilos_user_verification `type` ENUM
-- (created in 025, last widened in 032) so one sign-in ceremony can hold TWO live
-- challenges: the long URL-safe token of the link, and the short numeric code a
-- person types when the letter and the sign-in screen are on different devices.
-- Two rows rather than two columns because the halves carry separate attempt
-- ceilings and every verification lookup keys on the type; mirrors the framework
-- stub create_hilos_user_verification.sql.
--
-- Appended at the END of the member list rather than beside 'magic_link': that is
-- the only widening MySQL performs in place, and the stub has to describe the same
-- column a migrated table ends up with.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code') NOT NULL;
