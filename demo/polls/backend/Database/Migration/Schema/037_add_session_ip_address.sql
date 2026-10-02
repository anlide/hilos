-- Adds the network address of a session's last connection (HIL-1174).
-- Mirrors the framework stub create_hilos_session.sql.
--
-- Rewritten on every handshake beside the device label. No index or backfill: an
-- existing session learns its address on its next handshake.

ALTER TABLE `hilos_session`
    ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL AFTER `device_name`;
