ALTER TABLE `hilos_locale` ADD COLUMN `first_weekday` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `collation`;
UPDATE `hilos_locale` SET `first_weekday` = 0 WHERE `code` IN (
    'en-US', 'en-CA', 'en-IN', 'en-SG', 'en-ZA', 'en-PH', 'en-HK', 'en-IL',
    'zh-HK', 'zh-TW', 'zh-SG', 'es-MX', 'es-CO', 'es-PE', 'es-VE', 'hi-IN',
    'hi-NP', 'ar-SA', 'bn-BD', 'bn-IN', 'pt-PT', 'pt-BR', 'pt-MZ', 'id-ID',
    'fr-CA', 'ja-JP', 'ko-KR', 'th-TH', 'he-IL', 'ur-PK', 'ur-IN', 'ta-IN',
    'ta-SG', 'mr-IN', 'kn-IN', 'ml-IN', 'te-IN', 'pa-IN', 'pa-PK', 'my-MM',
    'am-ET', 'sw-KE', 'zu-ZA'
);
UPDATE `hilos_locale` SET `first_weekday` = 6 WHERE `code` IN (
    'ar-EG', 'ar-DZ', 'ar-IQ', 'ar-JO', 'ar-KW', 'fr-DZ', 'fa-IR', 'fa-AF'
);
ALTER TABLE `hilos_locale` MODIFY `first_weekday` TINYINT UNSIGNED NOT NULL;
