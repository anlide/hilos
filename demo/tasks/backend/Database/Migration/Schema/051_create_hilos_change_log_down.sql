-- Drop the separate journal schema's tables after checking that no history remains.

DELIMITER $$
CREATE OR REPLACE PROCEDURE `hilos_change_log_drop_guard`()
BEGIN
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_table`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_table is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_field`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_field is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_receipt`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_receipt is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_change`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_change is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_value`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_value is not empty';
    END IF;
END$$
DELIMITER ;
CALL `hilos_change_log_drop_guard`();
DROP PROCEDURE `hilos_change_log_drop_guard`;

DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_value`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_change`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_receipt`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_field`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_table`;
