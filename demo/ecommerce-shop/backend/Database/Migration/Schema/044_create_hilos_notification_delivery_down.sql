-- hilos-framework: {"format":1,"kind":"install","units":["notification_delivery"],"parts":[],"upSha256":"83e883f283b670f9f1cc4894cda604d608599304b9638d3964c2011bf0e8d52a","downSha256":"20060db3a4041f04299235dde117ac4e656020570c73a687d810c8247ab18b6b"}
-- Migration down: Drop hilos_notification_delivery table (stub/reference)

DROP TABLE IF EXISTS `hilos_notification_delivery`;
