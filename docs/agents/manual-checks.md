# What Only a Person Checks

A green run proves what automation can reach. This page is the single list of
what it cannot. HIL-658 is the case behind it: Windows stopped offering "this
computer" in its passkey window while every suite stayed green. All three demo
Playwright configs (`demo/{chat,polls,tasks}/tests/e2e/playwright.config.ts`) run
only Chromium; Safari and Firefox are checked by hand.

## A change that touches a listed surface

A change touching a surface listed below names the affected item numbers in its
hand-over for acceptance. The surface's paths identify what touches it. A person
runs those items on the devices available and records the result in one line
in that change's acceptance record:

```text
<surface> by hand: <platform>, <browser> — items <N–M> ok
```

Name failures and items not checked just as explicitly. The one-line entries
under **Other surfaces** are a map, not an obligation to run unwritten steps;
the change that reaches one writes its steps here and names them in its hand-over.

There is no calendar-based run. This is the owner's decision of September 26,
2026 (HIL-659): an OS or browser update breaking the ceremony without a change
on our side will be caught only by the next change to this surface. Revisit a
regular run when there are live installations.

## Passkey

The ceremony has three layers:

- **Request:** `framework/frontend/e2e/passkeyCreation.ts` records what the page
  hands to `navigator.credentials.create` and judges its algorithms against the
  promised platform table. `demo/chat/tests/e2e/tests/passkey.spec.ts` uses it for
  both creation paths: adding a key in the profile and starting an account on a key.
- **Outcome:** `framework/frontend/core/test/auth/passkeyCeremony.test.ts`
  covers the browser-result/error interpretation. The full key path for each
  declared algorithm — enrollment, stored database row, assertion — is covered
  by `framework/tests/Integration/PasskeyAlgorithmRoundTripIntegrationTest.php`.
- **Between them:** the OS window, its authenticator list, PIN/biometrics, key
  storage, synchronization and phone handoff are the person's checks below.

The CDP virtual authenticator has no algorithm-set option
(`VirtualAuthenticatorOptions`, Playwright 1.60.0), so it cannot replace the
platform table. That table is our knowledge, not a live device measurement;
a change in a platform's behavior is caught only by a manual run.

Paths that touch this surface:

- `framework/backend/Auth/WebAuthn/**`
- `framework/backend/Auth/Library/Command/PasskeyCommands.php`
- `framework/frontend/core/src/auth/passkey.ts`
- `framework/frontend/core/src/auth/passkeyCeremony.ts`
- `framework/frontend/core/src/auth/stepUp.ts`
- `HILOS_WEBAUTHN_*` settings in `framework/backend/Environment/EnvCatalogStub.php`

### Before the manual run

Set `HILOS_WEBAUTHN_RP_ID` to the host the browser opens and
`HILOS_WEBAUTHN_ORIGIN` to its exact origin (`scheme://host:port`). Defaults are
`localhost` and `http://localhost`; a different port needs its matching origin,
or the ceremony fails on origin validation.

A passkey works only at a secure address: HTTPS with a domain the device trusts,
or localhost. The local stand publishes its ports only on the machine it runs
on (`HILOS_BIND_HOST` defaults to `127.0.0.1` in
`demo/chat/docker/docker-compose.local.yml`). A Mac can reach that stand through
an SSH tunnel to its own localhost, keeping the same origin and RP id; an iPhone
cannot use that localhost tunnel. Items 4 and 5 require an HTTPS address
accessible from the phone. Without it, record **"not checked: no HTTPS address
for the phone"** for those items rather than silently skipping them.

### Promised platforms

| Platform | Browsers | Promised | Checked by hand |
|---|---|---|---|
| Windows + Windows Hello | Chrome | yes | refused 2026-08-22 at the acceptance of HIL-418 ("this computer" missing, fixed by HIL-658); not checked since |
| Mac — Touch ID, iCloud Keychain | Safari, Chrome | yes | never |
| iPhone — iCloud Keychain | Safari | yes | never |
| A phone by QR from a computer (hybrid) | Chrome | yes | never |
| Android — Google Password Manager | Chrome | yes | never |
| FIDO2 security key (USB/NFC) | Chrome | yes | never |
| U2F-only key without discoverable credentials | — | no — sign-in names no account, so the key must hold a discoverable credential (`buildRegistrationOptions()` in `framework/backend/Auth/Library/Command/PasskeyCommands.php` requires `residentKey: 'required'`) | — |

### Manual items

Keep these numbers stable: hand-overs refer to them.

1. Windows + Windows Hello, Chrome — Profile → Ways to sign in → Add a way to sign in → Passkey: the Windows window offers this computer; the key enrolls; "Passkey added." shows.
2. Same machine — Profile → Ways to sign in → Add a way to sign in → Passkey again, press Cancel in the Windows window: the dialog shows the cancellation in its refusal row and stays open.
3. Same machine — sign out, then "Sign in with a passkey" on an empty field: the Windows window offers the key; the sign-in completes.
4. iPhone, Safari — items 1 and 3; the key is saved to iCloud Keychain.
5. Mac, Safari — "Sign in with a passkey" with the key enrolled on the iPhone: the sign-in completes without enrolling a new key.
6. Windows or Mac, Chrome — "Sign in with a passkey" → use a phone → scan the QR with the iPhone → confirm on the phone: the sign-in completes on the computer.
7. Sign-up — "Create it with a passkey" on "Choose a password" (HIL-1104): the OS window enrolls the key; the account exists and signs in by it.
8. Step-up — an action that asks for confirmation, confirmed with the device key: the OS window appears and the action completes.
9. Sign-up without an address — "Create an account with a passkey" on an empty field (HIL-1106), on a device with a platform key: the terms screen, then the OS window enrolls the key; the account is named User + six digits and signs in by the key. On a machine without a platform key the line is absent.

## Other surfaces — one line each until a change reaches them

- Mail (Mailpit, real SMTP; `framework/backend/Mail/SmtpMailTransport.php`): how the HTML letter renders in a real mail client — specs read only the text part (`demo/chat/tests/e2e/helpers/mail.ts`); that the magic link points at the right host — specs keep only its path; deliverability (SPF/DKIM, the spam folder); the link opened on another device.
- SMS (stand gateway `/sms/send`; `framework/backend/Sms/HttpSmsProvider.php`): a real vendor driver, the sender name, delivery to a real number in its country's format.
- Telegram codes (stand gateway `/telegram`; `framework/backend/Auth/CodeChannel/TelegramCodeChannel.php`): delivery into the Telegram app on a phone.
- OAuth (stand gateway `/oauth`; `framework/backend/Auth/OAuth/HttpOAuthProvider.php`): the real provider's account chooser and consent screen; the redirect URI registered at the provider.
- Local model (stand gateway `/model`; `framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider.php`): a real model's own answer — the spec dictates the verdict.
- TOTP (`framework/backend/Auth/SecondFactor/Totp.php`): a real authenticator app scanning the enrollment QR — the spec computes the code itself (`demo/chat/tests/e2e/helpers/totp.ts`).
- Watchdog alert mail (`WATCHDOG_ALERT_SMTP_*` in `framework/backend/Environment/EnvCatalogStub.php`): the letter reaching an operator's inbox — no stand wires these settings.
- Web Push (`framework/backend/Push/WebPushRequestFactory.php`, `framework/backend/Push/Delivery/PushEndpointSend.php`): a notification shown through a real browser push service — no double exists (HIL-918).

## Adding to this page

Add a new surface as one line when a change discovers something automation
cannot reach. Add numbered steps when a change touches that surface, preserving
existing item numbers. After the first manual run on a platform, update its
**Checked by hand** cell in a small change linked to that acceptance record.
A promised platform stays visibly untested until there is such a record.
