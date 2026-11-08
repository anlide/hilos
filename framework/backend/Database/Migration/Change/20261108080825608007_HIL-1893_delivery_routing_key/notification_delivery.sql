ALTER TABLE `hilos_notification_delivery`
    ADD COLUMN `routing_key` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `channel`,
    DROP INDEX `idx_notification_delivery_channel_status`,
    ADD INDEX `idx_notification_delivery_channel_status` (`channel`, `status`, `id`);
