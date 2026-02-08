-- Initial database setup
-- This file is executed when MySQL container starts for the first time

-- Create default database if not exists
CREATE DATABASE IF NOT EXISTS `hilos-demo-chat` 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_0900_ai_ci;

-- Grant privileges to user
-- Note: User credentials come from environment variables in docker-compose
GRANT ALL PRIVILEGES ON `hilos-demo-chat`.* TO 'hilos'@'%';
FLUSH PRIVILEGES;

-- Use the database
USE `hilos-demo-chat`;

-- Example: Create initial user for testing (optional)
-- This will be managed by migrations in production

