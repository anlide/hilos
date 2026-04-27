-- Migration: Remove runtime presence events from chat history
-- Created: 2026-04-27
-- Index: 017

DELETE FROM `event`
WHERE `type` IN ('user_online', 'user_offline');
