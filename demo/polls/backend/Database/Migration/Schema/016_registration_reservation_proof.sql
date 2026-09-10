-- Moves the password out of the hold and puts the proof of the address in (HIL-825).
-- Mirrors the framework stub create_hilos_registration_reservation.sql.
--
-- A registration used to take the password at the address step, so the hold carried a
-- bcrypt hash of a credential chosen for an account nobody had proved they own - and an
-- abandoned registration kept it for the whole TTL. The password is asked for AFTER the
-- code now, and the account, its identity and its password are written together when
-- that password is saved, so there is nothing left for the hold to store.
--
-- What it stores instead is the moment the code was accepted. That mark is what a
-- returning tab is put back on the password screen by, and it is durable on purpose: a
-- browser that proved an address keeps the right to finish it across a reload, a closed
-- tab and a daemon restart. Nullable because a hold that is still waiting for its code
-- has not been proved yet.
--
-- No index and no backfill. The mark is read off a hold already in hand, and a hold
-- written before this column had no way to be proved without creating the account on
-- the spot - the rows that predate it are all unproved by construction.

ALTER TABLE `hilos_registration_reservation`
    DROP COLUMN `secret`,
    ADD COLUMN `code_accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `session_token`;
