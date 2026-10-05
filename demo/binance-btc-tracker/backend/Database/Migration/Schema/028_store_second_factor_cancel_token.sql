-- Migration: Store the second-factor cancel token as it is (HIL-1302)
-- Created: 2026-10-05
-- Index: 028
--
-- The cancel link is repeated in every reminder, so the row keeps the token
-- itself, not its hash. A hash cannot be turned back into the link. Standing
-- requests from before this change are removed: they have no token to repeat.
-- Ended requests stay, with the new column empty.

DELETE FROM `hilos_second_factor_reset`
WHERE `canceled_at` IS NULL AND `completed_at` IS NULL;

ALTER TABLE `hilos_second_factor_reset`
    DROP COLUMN `cancel_token_hash`,
    ADD COLUMN `cancel_token` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL AFTER `effective_at`;
