-- hilos-framework: {"format":1,"kind":"change","units":["file_variant"],"parts":[{"changeId":"20261109114206000000_HIL-1926_file_moments","unit":"file_variant","upSha256":"6a9e036d65400a184e17422444eb66542c88e05d06e5557757817a124af55168","downSha256":"a9b2417d0380636576e59db48eea52daeedb8c97e551ff96dd8df78d94341ba1"}],"upSha256":"6a9e036d65400a184e17422444eb66542c88e05d06e5557757817a124af55168","downSha256":"a9b2417d0380636576e59db48eea52daeedb8c97e551ff96dd8df78d94341ba1"}
ALTER TABLE `hilos_file_variant` ADD COLUMN `__hil1926_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_file_variant` SET `__hil1926_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_file_variant` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1926_created_at` `created_at` BIGINT NOT NULL;
