-- Migration: Create hilos_notification_delivery table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_notification_delivery.sql)
--
-- Channel delivery of durable notifications (HIL-196). One row is one channel's
-- delivery of one notification: the dispatcher (folded into Hilos::$notify->emit())
-- inserts it `pending` when it fans a notification to a resolved channel, and that
-- channel's delivery agent moves it to `sent`/`failed` with bounded retries.
--
-- No DB-level foreign key to hilos_notification: framework stubs never FK across a
-- boundary, and even the framework-owned notification is referenced softly so a row
-- can be pruned independently. `channel` is the channel name (a string, not an enum:
-- channels are extended by subclassing, not by a fixed set).
--
-- Indexes: (notification_id) resolves a notification's deliveries; (channel, status)
-- serves per-channel queue/retry scans; (created_at) and (status, created_at) serve
-- the delivery-logs window and status/age retention scans (HIL-201).

CREATE TABLE `hilos_notification_delivery` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(50) NOT NULL,
    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_notification_delivery_notification` (`notification_id`),
    KEY `idx_notification_delivery_channel_status` (`channel`, `status`),
    KEY `idx_notification_delivery_created` (`created_at`),
    KEY `idx_notification_delivery_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
