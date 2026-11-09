-- hilos-framework: {"format":1,"kind":"change","units":["file"],"parts":[{"changeId":"20261109114206000000_HIL-1926_file_moments","unit":"file","upSha256":"9bf624040b50bf6233db92de60e9450fff4d3012809bc7af0535365e7aa47884","downSha256":"f43f297700aacb6d3eecdb25b6b973b523abece0b1672f6683bacf88d65b6c4f"}],"upSha256":"9bf624040b50bf6233db92de60e9450fff4d3012809bc7af0535365e7aa47884","downSha256":"f43f297700aacb6d3eecdb25b6b973b523abece0b1672f6683bacf88d65b6c4f"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_file` WHERE `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'File moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_file` ADD COLUMN `__hil1926_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_file` SET `__hil1926_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_file` DROP INDEX `idx_file_bound_created`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1926_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD KEY `idx_file_bound_created` (`bound`, `created_at`);
END$$
DELIMITER ;
