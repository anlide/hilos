DELETE FROM `hilos_auth_block` WHERE `scope` = 'email';
ALTER TABLE `hilos_auth_block` MODIFY COLUMN `scope` ENUM('ip', 'session') NOT NULL;
