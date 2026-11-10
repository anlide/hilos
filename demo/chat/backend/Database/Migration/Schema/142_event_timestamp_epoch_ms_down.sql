ALTER TABLE `event` ADD COLUMN `__hil1930_timestamp` DATETIME NULL;
UPDATE `event` SET `__hil1930_timestamp` = TIMESTAMPADD(SECOND, FLOOR(`timestamp` / 1000), '1970-01-01 00:00:00');
ALTER TABLE `event` DROP KEY `timestamp`, DROP COLUMN `timestamp`, CHANGE COLUMN `__hil1930_timestamp` `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD KEY `timestamp` (`timestamp`);
