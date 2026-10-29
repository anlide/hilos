ALTER TABLE `hilos_status_incident` ADD INDEX `idx_status_incident_detected` (`detected_at` DESC, `id` DESC);
