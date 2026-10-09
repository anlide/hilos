ALTER TABLE {{change_log_database}}.`hilos_change_log_receipt`
    ADD COLUMN `open_handovers` INT NOT NULL DEFAULT 0 AFTER `source`;
