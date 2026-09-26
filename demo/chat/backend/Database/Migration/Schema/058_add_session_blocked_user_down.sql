-- Reverts 058: drops the blocked account column and the two indexes it brought.

ALTER TABLE `hilos_session`
    DROP KEY `idx_session_impersonator`,
    DROP KEY `idx_session_blocked_user`,
    DROP COLUMN `blocked_user_id`;
