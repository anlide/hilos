-- Reverts 021: drops the display label derived from the session User-Agent.

ALTER TABLE `hilos_session`
    DROP COLUMN `device_name`;
