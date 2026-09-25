-- Adds the two indexes used by the sessions library sweep (HIL-1075).
-- The sweep runs every 15 minutes over the hot session table: one index finds
-- expired rows, the other finds anonymous rows by their last visit.

ALTER TABLE `hilos_session`
    ADD KEY `idx_session_expires` (`expires_at`),
    ADD KEY `idx_session_anonymous_seen` (`user_id`, `last_seen_at`);
