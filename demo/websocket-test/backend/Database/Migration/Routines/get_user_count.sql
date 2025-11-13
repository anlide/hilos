-- Stored Procedure: Get user count by status
-- This is an example of a SQL routine

DELIMITER $$

DROP PROCEDURE IF EXISTS GetUserCount$$

CREATE PROCEDURE GetUserCount(
    IN p_admin TINYINT,
    IN p_blocked TINYINT
)
BEGIN
    SELECT COUNT(*) as user_count
    FROM `user`
    WHERE 
        (p_admin IS NULL OR `admin` = p_admin)
        AND (p_blocked IS NULL OR `block` = p_blocked);
END$$

DELIMITER ;

