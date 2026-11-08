ALTER TABLE `hilos_notification_delivery`
    DROP COLUMN `routing_key`,
    DROP INDEX `idx_notification_delivery_channel_status`,
    ADD INDEX `idx_notification_delivery_channel_status` (`channel`, `status`);
