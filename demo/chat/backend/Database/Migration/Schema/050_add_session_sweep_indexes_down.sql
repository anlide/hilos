-- Reverts 050: drops the two indexes used by the sessions library sweep.

ALTER TABLE `hilos_session`
    DROP KEY `idx_session_anonymous_seen`,
    DROP KEY `idx_session_expires`;
