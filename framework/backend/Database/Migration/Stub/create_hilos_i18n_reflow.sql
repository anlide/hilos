-- Migration: Create hilos_i18n_reflow table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_i18n_reflow.sql)
--
-- The fingerprint of the built-in i18n catalog last taken into the reference tables
-- (HIL-1472). One row, id always 1. Only the i18n library writes it, as the last step
-- of the reflow and inside its transaction, so a reflow that fails leaves the old
-- fingerprint and the next start runs it again.
--
-- It lives in the database and not in a file: a restore rewrites the database and not a
-- file, so a restored archive with an older catalog would otherwise never be reflowed,
-- and in a cluster every node would keep a file of its own. It is not a row of
-- hilos_setting either: that table is held whole by the settings library, and a key the
-- settings catalog does not know is shown as an orphan with a delete button - deleting
-- it would quietly trigger a reflow.
CREATE TABLE `hilos_i18n_reflow` (
    `id` TINYINT UNSIGNED NOT NULL,
    `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_i18n_reflow_one_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
