-- hilos-framework: {"format":1,"kind":"install","units":["account_merge_copy"],"parts":[{"changeId":"20261116090449292760_HIL-1992_account_merge_copy","unit":"account_merge_copy","upSha256":"2595b1772a623bad259e9870ca432737d8371be0fae0d423c19e2b1dc21d6a74","downSha256":"28de63e1a1e9161d0f1c102bac10edcfea81167ca084c81677c42f4f9cf1c3ca"}],"upSha256":"2595b1772a623bad259e9870ca432737d8371be0fae0d423c19e2b1dc21d6a74","downSha256":"28de63e1a1e9161d0f1c102bac10edcfea81167ca084c81677c42f4f9cf1c3ca"}
CREATE TABLE IF NOT EXISTS `hilos_account_merge_copy` (
    `assignment_id` BIGINT UNSIGNED NOT NULL,
    `owner_agent_id` VARCHAR(383) COLLATE utf8mb4_bin NOT NULL,
    `payload_json` JSON NULL DEFAULT NULL,
    `created_at` BIGINT NOT NULL,
    PRIMARY KEY (`assignment_id`),
    KEY `idx_account_merge_copy_owner` (`owner_agent_id`, `assignment_id`),
    CONSTRAINT `fk_account_merge_copy_assignment` FOREIGN KEY (`assignment_id`)
        REFERENCES `hilos_account_operation_assignment` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
