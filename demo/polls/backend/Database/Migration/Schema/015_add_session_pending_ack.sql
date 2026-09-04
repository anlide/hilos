-- Gives the success announcement an owner of its own (HIL-875). Mirrors the framework
-- stub create_hilos_session.sql.
--
-- The mark a finished auth flow leaves - the account is ready, the password changed -
-- used to live on the connection row that earned it, and its lifetime was the socket's.
-- Nothing tied it to the session, so it outlived the person it was about: a logout
-- restated it to every tab, and a login rotation carried it onto the socket that
-- replaced the marked one. What the announcement is about is the ACCOUNT, and what
-- shows it is the SESSION, so the session row is what remembers it.
--
-- No index and no backfill. Nothing looks a session up by the mark - it is read off a
-- session already in hand - and no row can owe an announcement that predates the
-- column: before it, the mark lived in memory and died with the process.

ALTER TABLE `hilos_session`
    ADD COLUMN `pending_ack` VARCHAR(64) DEFAULT NULL AFTER `pending_registration_since`;
