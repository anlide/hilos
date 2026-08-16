-- Migration (down): Move the session token back onto the user row
-- Index: 006
--
-- Restores `user.session_token` exactly as migration 002 left it, moves each
-- session's token back to the user it is bound to, then drops hilos_session.
--
-- An anonymous session (no bound user) has no user row to move its token to and
-- is dropped with the table: before this migration the demo had no way to
-- express a session without a user, so there is nowhere to put it back.

ALTER TABLE `user`
    ADD COLUMN `session_token` VARCHAR(32) DEFAULT NULL AFTER `block`,
    ADD UNIQUE KEY `session_token` (`session_token`);

UPDATE `user`
JOIN `hilos_session` ON `hilos_session`.`user_id` = `user`.`id`
SET `user`.`session_token` = `hilos_session`.`token`;

DROP TABLE `hilos_session`;
