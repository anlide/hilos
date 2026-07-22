-- Migration (down): Drop the account-merge tombstone marker
-- Index: 029

ALTER TABLE `user`
    DROP COLUMN `merged_into`;
