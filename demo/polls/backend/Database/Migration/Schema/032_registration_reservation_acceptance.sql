-- Keep accepted legal revisions with the registration hold (HIL-499).
ALTER TABLE `hilos_registration_reservation`
    ADD COLUMN `accepted_revisions` JSON NULL DEFAULT NULL AFTER `code_accepted_at`;
