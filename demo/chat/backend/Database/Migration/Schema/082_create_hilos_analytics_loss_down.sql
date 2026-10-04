-- Migration Rollback: Remove the analytics loss account (HIL-1157)

DROP TABLE IF EXISTS `hilos_analytics_loss`;
