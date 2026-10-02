-- Reverts 024: drops how a browser got its "Access closed" card.

ALTER TABLE `hilos_session`
    DROP COLUMN `blocked_signed_in`;
