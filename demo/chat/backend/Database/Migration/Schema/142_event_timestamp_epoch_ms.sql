ALTER TABLE `event` ADD COLUMN `__hil1930_timestamp` BIGINT NULL;
UPDATE `event` SET `__hil1930_timestamp` = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `timestamp`) DIV 1000;
ALTER TABLE `event` DROP KEY `timestamp`, DROP COLUMN `timestamp`, CHANGE COLUMN `__hil1930_timestamp` `timestamp` BIGINT NOT NULL, ADD KEY `timestamp` (`timestamp`);
