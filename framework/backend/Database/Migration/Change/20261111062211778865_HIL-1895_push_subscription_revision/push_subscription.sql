ALTER TABLE `hilos_push_subscription`
    ADD COLUMN `revision` BIGINT NOT NULL DEFAULT 1 AFTER `last_seen_at`;

ALTER TABLE `hilos_push_subscription`
    MODIFY COLUMN `revision` BIGINT NOT NULL;
