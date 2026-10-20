-- hilos-framework: {"format":1,"kind":"install","units":["oauth_provider"],"parts":[],"upSha256":"04353bad1214d738efe8f07bebcfdb5578aa81b408ae5f4895e16e9290c01bb9","downSha256":"fc6499840a3ab2258c08a7d0991278b371aaefd49e66809df2a7704d1c6f7351"}
-- Migration down: Drop hilos_oauth_provider table (stub/reference)

DROP TABLE IF EXISTS `hilos_oauth_provider`;
