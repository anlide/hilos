-- Reverts 038: drops the `channel` column from hilos_user_verification.
-- The column is pure delivery bookkeeping - no code is verified through it - so
-- dropping it loses only which channel past phone codes went out over.

ALTER TABLE `hilos_user_verification`
    DROP COLUMN `channel`;
