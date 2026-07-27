-- Migration: Create hilos_notification table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_notification.sql)
--
-- Durable (server-backed) notification model (HIL-102). One row is a delivered
-- notification for one recipient. The row is written by the emitting worker
-- through Hilos::$notify->emit(); a live in-app signal is fanned best-effort, and
-- the unread badge recovers from a COUNT on subscribe when no live connection was
-- present at emit time.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. `user_id` is the recipient, indexed for
-- the per-user list and unread count.
--
-- `type` is a machine key (e.g. backup.completed); `title`/`body` are rendered at
-- emit time in the default locale; `data` carries structured context so a later
-- i18n pass can re-render from (type, data).

CREATE TABLE `hilos_notification` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(100) NOT NULL,
    `severity` ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info',
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT DEFAULT NULL,
    `data` JSON DEFAULT NULL,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notification_user_read` (`user_id`, `read_at`),
    KEY `idx_notification_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
