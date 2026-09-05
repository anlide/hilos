-- Reverts 045: drops the hilos_verifier_circle table.
-- A circle standing when the rollback runs is lost with the table, which is the same
-- thing a restore does to it: the circle never survives a database replacement.

DROP TABLE IF EXISTS `hilos_verifier_circle`;
