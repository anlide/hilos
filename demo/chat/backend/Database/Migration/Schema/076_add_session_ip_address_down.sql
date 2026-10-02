-- Reverts 076: drops the network address of a session's last connection.

ALTER TABLE `hilos_session`
    DROP COLUMN `ip_address`;
