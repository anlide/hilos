-- hilos-framework: {"format":1,"kind":"change","units":["notification_delivery"],"parts":[{"changeId":"20261108080825608007_HIL-1893_delivery_routing_key","unit":"notification_delivery","upSha256":"1d5d491a3adf33a6f309ff53d50fc29415e25b3b6769db0ee1eb2741a36bb10f","downSha256":"9d5762c5df5d80009efb79a1a90b3cb3fb63305a80cb11ea9c2f0dc85d600209"}],"upSha256":"1d5d491a3adf33a6f309ff53d50fc29415e25b3b6769db0ee1eb2741a36bb10f","downSha256":"9d5762c5df5d80009efb79a1a90b3cb3fb63305a80cb11ea9c2f0dc85d600209"}
ALTER TABLE `hilos_notification_delivery`
    ADD COLUMN `routing_key` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `channel`,
    DROP INDEX `idx_notification_delivery_channel_status`,
    ADD INDEX `idx_notification_delivery_channel_status` (`channel`, `status`, `id`);
