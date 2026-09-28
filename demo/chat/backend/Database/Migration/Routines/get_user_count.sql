-- Stored Procedure: Get user count
-- This is an example of a SQL routine

DELIMITER $$

DROP PROCEDURE IF EXISTS GetUserCount$$

CREATE PROCEDURE GetUserCount()
BEGIN
    SELECT COUNT(*) as user_count
    FROM `hilos_user`;
END$$

DELIMITER ;

