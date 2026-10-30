-- hilos-framework: {"format":1,"kind":"change","units":["identity"],"parts":[{"changeId":"20261023065022000000_HIL-1653_identity_display_fields","unit":"identity","upSha256":"48d533d4b3de2d97b0e816b80a988115463d0aa9f51a136247a59a34a836bcbb","downSha256":"2f695e56ce625ca6ee9def7ff38f2010ff025d293953937330338ff9fb95f0c6"}],"upSha256":"48d533d4b3de2d97b0e816b80a988115463d0aa9f51a136247a59a34a836bcbb","downSha256":"2f695e56ce625ca6ee9def7ff38f2010ff025d293953937330338ff9fb95f0c6"}
ALTER TABLE `hilos_identity`
    ADD COLUMN `password_changed_at` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `verified`,
    ADD COLUMN `provider_name` TEXT NULL AFTER `password_changed_at`;
