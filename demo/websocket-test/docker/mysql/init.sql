-- Initial database setup
-- This file is executed when MySQL container starts for the first time

-- Create default database if not exists
CREATE DATABASE IF NOT EXISTS `hilos-demo-websocket` 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_0900_ai_ci;

-- Grant privileges to user
-- Note: User credentials come from environment variables in docker-compose
GRANT ALL PRIVILEGES ON `hilos-demo-websocket`.* TO 'hilos'@'%';
FLUSH PRIVILEGES;

-- Use the database
USE `hilos-demo-websocket`;

-- Example: Create initial user for testing (optional)
-- This will be managed by migrations in production

