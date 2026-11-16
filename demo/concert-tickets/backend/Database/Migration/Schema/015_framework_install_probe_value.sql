-- hilos-framework: {"format":1,"kind":"install","units":["probe_value"],"parts":[{"changeId":"20261108011951239942_HIL-1892_add_probe_values","unit":"probe_value","upSha256":"16d00e42a5c5385233cd8a85b66dc6f6cb7647dc6bbb280a23e5693aac7f43f3","downSha256":"0af9954d66ed106021dd4154016991313bffefdb0be972d12afc986198bee3d9"}],"upSha256":"16d00e42a5c5385233cd8a85b66dc6f6cb7647dc6bbb280a23e5693aac7f43f3","downSha256":"0af9954d66ed106021dd4154016991313bffefdb0be972d12afc986198bee3d9"}
-- Migration: Create hilos_probe_value table (stub/reference)
--
-- Synthetic cluster DB probe values belong to the writing node's set. They live
-- outside hilos_setting because the settings library owns that table whole;
-- a per-node probe must not become another changing writer of its settings.
-- node_id is a soft root: a cluster node has no persisted parent row.
CREATE TABLE `hilos_probe_value` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `node_id` VARCHAR(64) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
