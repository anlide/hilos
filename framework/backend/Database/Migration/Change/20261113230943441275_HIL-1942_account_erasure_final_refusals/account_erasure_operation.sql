ALTER TABLE `hilos_account_operation`
    ADD COLUMN `final_refusals` JSON DEFAULT NULL AFTER `outcome`;
