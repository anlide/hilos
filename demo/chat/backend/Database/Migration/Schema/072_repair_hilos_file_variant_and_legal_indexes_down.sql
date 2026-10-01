-- Reverts the repair. On a fresh database the table is created here, so it goes;
-- the legal administration indexes belong to 063 and are dropped by its down.

DROP TABLE IF EXISTS `hilos_file_variant`;
