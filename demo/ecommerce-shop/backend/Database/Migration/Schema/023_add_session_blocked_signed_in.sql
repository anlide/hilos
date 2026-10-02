-- Adds how a browser got its "Access closed" card (HIL-1188).
-- Mirrors the framework stub create_hilos_session.sql.
--
-- 1 - the browser was inside the account when the block threw it out, and an unblock
-- signs it back in; 0 - it was refused at sign-in, and an unblock walks it through the
-- second-factor gate. Read off the row already in hand, so it carries no index.
-- No backfill: a card raised before this column is treated as refused at sign-in, which
-- only walks it through the second-factor gate.

ALTER TABLE `hilos_session`
    ADD COLUMN `blocked_signed_in` TINYINT(1) NOT NULL DEFAULT 0 AFTER `blocked_user_id`;
