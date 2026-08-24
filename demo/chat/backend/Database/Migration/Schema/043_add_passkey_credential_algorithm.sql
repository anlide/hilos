-- Records which signature suite each passkey was enrolled with (HIL-658). Mirrors
-- the framework stub create_hilos_passkey_credential.sql.
--
-- The ceremony now offers RS256 next to ES256, so that a Windows Hello platform
-- authenticator - which offers nothing else - stops being dropped out of the OS
-- picker. Once two suites are on offer the row has to say which one it holds: RSA
-- keys of the PKCS1 and PSS schemes share one kind of key material, so a stored PEM
-- alone cannot tell a login what to check the signature against.
--
-- The column arrives with DEFAULT -7 (ES256) and loses it in the same migration.
-- Every row written until now came from a ceremony that offered ES256 and nothing
-- else, so the default is the truth for them; keeping it afterwards would turn a
-- caller that forgot to pass an algorithm into a silent ES256 registration.

ALTER TABLE `hilos_passkey_credential`
    ADD COLUMN `algorithm` SMALLINT NOT NULL DEFAULT -7 AFTER `public_key`;

ALTER TABLE `hilos_passkey_credential`
    ALTER COLUMN `algorithm` DROP DEFAULT;
