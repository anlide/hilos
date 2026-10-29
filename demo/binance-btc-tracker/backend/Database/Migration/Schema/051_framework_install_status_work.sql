-- hilos-framework: {"format":1,"kind":"install","units":["status_work"],"parts":[{"changeId":"20261029024734382401_HIL-1692_status_works","unit":"status_work","upSha256":"da69f3f5731f30bbd5b527b4d6dfe87b430a08d3f327386a0d3595d1ca011ad3","downSha256":"5c737331e60001c450c9a28d4a9fffe7b289f82c75a73d241edf42611344935d"}],"upSha256":"da69f3f5731f30bbd5b527b4d6dfe87b430a08d3f327386a0d3595d1ca011ad3","downSha256":"5c737331e60001c450c9a28d4a9fffe7b289f82c75a73d241edf42611344935d"}
CREATE TABLE `hilos_status_work` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(32) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `starts_at` BIGINT UNSIGNED NOT NULL,
    `ends_at` BIGINT UNSIGNED NOT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `updated_at` BIGINT UNSIGNED NOT NULL,
    `canceled_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `consumed_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `expired_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `version` INT UNSIGNED NOT NULL,
    `last_mail_queued_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_work_public` (`public_id`),
    KEY `idx_status_work_pending_starts_at` (`canceled_at`, `consumed_at`, `expired_at`, `starts_at`, `id`),
    KEY `idx_status_work_pending_ends_at` (`canceled_at`, `consumed_at`, `expired_at`, `ends_at`, `id`),
    KEY `idx_status_work_pending_title` (`canceled_at`, `consumed_at`, `expired_at`, `title`, `id`),
    KEY `idx_status_work_window` (`canceled_at`, `starts_at`, `ends_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
