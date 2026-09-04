-- Reverts 044: drops the `pending_ack` column from hilos_session.
-- An announcement standing when the rollback runs is lost with the column, which is
-- what the mark did on every daemon restart before it had a column at all.

ALTER TABLE `hilos_session`
    DROP COLUMN `pending_ack`;
