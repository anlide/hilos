-- Reverts 018: drops the hilos_oauth_provider table.
-- What an administrator entered for the providers is lost with it, and every provider
-- falls back to its env values.

DROP TABLE IF EXISTS `hilos_oauth_provider`;
