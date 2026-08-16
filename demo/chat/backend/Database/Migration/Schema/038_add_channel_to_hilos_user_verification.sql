-- Adds the `channel` column to hilos_user_verification (HIL-492): the delivery
-- channel a phone code was explicitly sent over, so a resend repeats the channel
-- the person chose instead of falling back to the type's default transport.
--
-- Nullable with no default value on purpose. NULL means "delivered by the type's
-- own rule" - every email type, and the profile add-phone flow, which stays a
-- plain SMS - so every existing row keeps its meaning and no backfill is needed.
-- VARCHAR rather than an ENUM because the channel set is a code registry a project
-- composes (see CodeChannelRegistry), and a new channel must not need a migration.
-- Mirrors the framework stub create_hilos_user_verification.sql.

ALTER TABLE `hilos_user_verification`
    ADD COLUMN `channel` VARCHAR(32) DEFAULT NULL AFTER `identifier`;
