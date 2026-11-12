-- hilos-framework: {"format":1,"kind":"install","units":["second_factor_trust_restriction"],"parts":[{"changeId":"20261112084322628728_HIL-1900_second_factor_trust_restriction","unit":"second_factor_trust_restriction","upSha256":"f5adc60277e32e6aeb9ff4555b11b04b67847630c6bcf21438cefc8f91c36181","downSha256":"da342bdc6bb0ee3fc392d99185c0bc2956ad9664c64f6e49333429ea3f762ea9"}],"upSha256":"f5adc60277e32e6aeb9ff4555b11b04b67847630c6bcf21438cefc8f91c36181","downSha256":"da342bdc6bb0ee3fc392d99185c0bc2956ad9664c64f6e49333429ea3f762ea9"}
-- Migration down: Drop hilos_second_factor_trust_restriction table (stub/reference)

DROP TABLE IF EXISTS `hilos_second_factor_trust_restriction`;
