-- Adds the display label derived from a session browser's User-Agent (HIL-288).
-- Mirrors the framework stub create_hilos_session.sql.
--
-- No index or backfill: the label is read from a session already selected by id or
-- user, and an existing session remains an unknown device until its next handshake.

ALTER TABLE `hilos_session`
    ADD COLUMN `device_name` VARCHAR(64) DEFAULT NULL AFTER `pending_second_factor_ack`;
