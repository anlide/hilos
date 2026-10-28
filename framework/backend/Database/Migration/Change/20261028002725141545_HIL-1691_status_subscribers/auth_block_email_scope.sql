ALTER TABLE `hilos_auth_block` MODIFY COLUMN `scope` ENUM('ip', 'session', 'email') NOT NULL;
