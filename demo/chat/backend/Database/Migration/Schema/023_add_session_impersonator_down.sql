-- Migration (down): Drop the session impersonator marker
-- Index: 023

ALTER TABLE `session`
    DROP COLUMN `impersonator_user_id`;
