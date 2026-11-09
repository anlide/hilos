ALTER TABLE `hilos_status_subscriber` MODIFY COLUMN `subscribed_at` BIGINT NOT NULL, MODIFY COLUMN `confirmed_at` BIGINT NULL, MODIFY COLUMN `confirmation_expires_at` BIGINT NULL;
