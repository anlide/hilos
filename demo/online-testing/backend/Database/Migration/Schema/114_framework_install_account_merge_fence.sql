-- hilos-framework: {"format":1,"kind":"install","units":["account_merge_fence"],"parts":[{"changeId":"20261116191447189798_HIL-1993_account_merge_fence","unit":"account_merge_fence","upSha256":"04e7a3fc155fcf60405e6c0b7cfd03c6d244da4ac0867d0c5e817e0301807bdb","downSha256":"3cbc34d4752d920d0d26d750c80fedb6dd6bdd1e800defb218ae5610c3f2f831"}],"upSha256":"04e7a3fc155fcf60405e6c0b7cfd03c6d244da4ac0867d0c5e817e0301807bdb","downSha256":"3cbc34d4752d920d0d26d750c80fedb6dd6bdd1e800defb218ae5610c3f2f831"}
CREATE TABLE IF NOT EXISTS `hilos_account_merge_fence` (
    `collection_key` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    `row_id` BIGINT UNSIGNED NOT NULL,
    `operation_id` BIGINT UNSIGNED NOT NULL,
    `assignment_id` BIGINT UNSIGNED NOT NULL,
    `owner_user_id` BIGINT UNSIGNED NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL,
    `deleted` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`collection_key`, `row_id`),
    KEY `idx_account_merge_fence_owner` (`owner_user_id`, `collection_key`, `row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
