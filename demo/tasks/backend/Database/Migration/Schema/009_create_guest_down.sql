-- Migration (down): Drop guest
-- Index: 009
-- Description:
--   Drops the table and stops there: the visitors the up removed are NOT put
--   back. Nothing is lost by that. The old code minted a visitor's row on its
--   first handshake anyway, so a returning browser gets one again; and the pair
--   name<->session could only be restored by guessing which non-unique Guest####
--   belonged to which cookie.

DROP TABLE IF EXISTS `guest`;
