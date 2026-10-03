-- Migration: Create hilos_account_deletion table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_account_deletion.sql)
--
-- A person's own request to delete their account (HIL-302). The request is confirmed with a
-- code and then waits out a grace period the administrator sets: the account is erased at
-- `effective_at`, which is fixed when the request is made, and until then everything works
-- as usual and a single "Keep my account" cancels it.
--
-- A request is live while both `canceled_at` and `completed_at` are NULL; the writes that
-- set either carry that condition, so a cancel and the erasure racing each other end with
-- exactly one of them. A carried-out row stays after the erasure: the number of an account
-- that no longer exists and three dates are the trace that it was erased on request, and
-- nothing else about the person is kept here.
-- Its user_id deliberately has no foreign key: RESTRICT would block the erasure,
-- SET NULL would lose the number, and CASCADE would erase this trace. The export
-- builder also reads completed requests to discard a copy built during erasure.

CREATE TABLE `hilos_account_deletion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `effective_at` TIMESTAMP NOT NULL,
    `canceled_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_account_deletion_effective` (`effective_at`),
    KEY `idx_account_deletion_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
