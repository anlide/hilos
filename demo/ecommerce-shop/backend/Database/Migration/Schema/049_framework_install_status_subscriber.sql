-- hilos-framework: {"format":1,"kind":"install","units":["status_subscriber"],"parts":[{"changeId":"20261028002725141545_HIL-1691_status_subscribers","unit":"status_subscriber","upSha256":"18962a8e4eee76f7c6454b0fdfac4cc533056030deb96ffd95b2c6e9b1eb86fb","downSha256":"1989af400b2b333b4e8dec23c200469e11ea1d419bd5874948ed3a278dfa1470"}],"upSha256":"18962a8e4eee76f7c6454b0fdfac4cc533056030deb96ffd95b2c6e9b1eb86fb","downSha256":"1989af400b2b333b4e8dec23c200469e11ea1d419bd5874948ed3a278dfa1470"}
CREATE TABLE `hilos_status_subscriber` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(254) NOT NULL,
    `subscribed_at` BIGINT UNSIGNED NOT NULL,
    `confirmed` BOOLEAN NOT NULL DEFAULT FALSE,
    `confirmed_at` BIGINT UNSIGNED NULL,
    `confirmation_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `confirmation_expires_at` BIGINT UNSIGNED NULL,
    `unsubscribe_token` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `browser_time_zone` VARCHAR(64) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_subscriber_email` (`email`),
    UNIQUE KEY `uk_status_subscriber_confirmation_token` (`confirmation_token_hash`),
    UNIQUE KEY `uk_status_subscriber_unsubscribe_token` (`unsubscribe_token`),
    KEY `idx_status_subscriber_subscribed` (`subscribed_at` DESC, `id` DESC),
    KEY `idx_status_subscriber_email` (`email` ASC, `id` ASC),
    KEY `idx_status_subscriber_confirmed` (`confirmed` ASC, `id` ASC),
    KEY `idx_status_subscriber_confirmed_subscribed` (`confirmed` ASC, `subscribed_at` DESC, `id` DESC),
    KEY `idx_status_subscriber_confirmed_email` (`confirmed` ASC, `email` ASC, `id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
