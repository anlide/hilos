-- Stored Procedure: Get user count
-- This is an example of a SQL routine
-- Note: admin and block fields have been removed, so this procedure now just returns total count

DELIMITER $$

DROP PROCEDURE IF EXISTS GetUserCount$$

CREATE PROCEDURE GetUserCount()
BEGIN
    SELECT COUNT(*) as user_count
    FROM `user`;
END$$

DELIMITER ;

