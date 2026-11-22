-- Seed: 002_hilos_vouchers
-- Three example vouchers. Safe to run again: a code already stored keeps its row and its
-- id, and only a missing code is inserted. Nothing is deleted or emptied, and an existing
-- discount or active flag is left as stored. Setting id to itself changes neither code,
-- discount_percent nor active, so the update journal writes no row.

INSERT INTO `voucher` (`code`, `discount_percent`, `active`) VALUES
('SPRING10', 10, 1),
('WEDDING15', 15, 1),
('WELCOME5', 5, 0)
ON DUPLICATE KEY UPDATE `id` = `voucher`.`id`;
