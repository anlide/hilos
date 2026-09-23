-- Adds the 'email_change_current' verification type for the profile change-email
-- flow (HIL-299). Extends the hilos_user_verification `type` ENUM (created in
-- 011) with the code that goes to the address the account holds NOW, which the
-- flow asks for before it mails the new address its own 'email_change' code. The two
-- letters carry two different warnings, so the two halves are two types; mirrors the
-- framework stub create_hilos_user_verification.sql.
--
-- Polls never runs the flow (it has no profile), but its column has to describe
-- the same member list as the stub.
--
-- Appended at the END of the member list: that is the only widening MySQL performs
-- in place, and the stub has to describe the same column a migrated table ends up with.

ALTER TABLE `hilos_user_verification`
    MODIFY `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code', 'email_change_current') NOT NULL;
