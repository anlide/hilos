# The Stand's Emulated External Services

Read this before a spec needs an external service on the stand — adding a
channel or an emulator, choosing between a stub and an emulator, or looking for
where a caught message lands. The house is `framework/docker/stand-gateway`
(HIL-492, HIL-653); this page is the rule for living in it, written for the
author of the next resident rather than as a description of the three that live
there today. What a particular future resident looks like — a model — is that
leaf's own design (HIL-925); this page says only what the house guarantees and
what a resident owes it. The OAuth provider has moved in (HIL-923) and the three
demos sign in through it (HIL-924); what it taught the house is written into the
rules below rather than described here. How to run the suites is
[testing.md](testing.md), not here.

## Why a Stand Emulates Rather Than Stubs

An in-process stub sends no byte. It proves what the code does with an answer it
was handed, and nothing about the road the answer travels: the request that was
built, the socket it went out on, the envelope that came back, the moment the
peer closed. `StubOAuthProvider`, the stub the demos signed in through until
HIL-924 removed it, said it of itself — "No network, no sockets" (its class
docblock): its authorize URL bounced the browser straight back to the SPA callback
with a canned code, and no exchange ever happened.

The cost of that is on record. Twelve closed OAuth leaves (HIL-281 … HIL-732)
were verified over that stub. The one defect found in that layer, HIL-732, was
found on a live provider and not by any test, and its regression is the only
fork in the unit suite — `serveTlsResponseInChild()` in
`framework/tests/Unit/AsyncHttpClientTest.php` — because nothing on the stand
could play the peer.

An emulator is the other thing: a separate process the product reaches over the
same transport it uses in production. The daemon really builds the request,
really posts it, and really reads the envelope back; the spec reads what came
out the other end. Mail is the precedent that already holds — Mailpit on the
stand, `SmtpMailTransport` speaking real SMTP to it (the daemon's
`MAIL_SMTP_HOST` / `MAIL_SMTP_PORT` point at it, port 1025 inside the compose
network, `MAIL_SMTP_SECURITY=none`), the letter read back by
`demo/chat/tests/e2e/helpers/mail.ts` — and SMS and Telegram codes are the two
residents that followed it into the gateway.

**Transport is proved on the stand, against an emulator.** A stub does not prove
it, and a suite that skips the transport proves only that the code compiles
(the gateway's `Dockerfile`, header comment). What that means for the stubs that
exist today is the sunset rule below.

## One Container, One Prefix Per Resident

The house is one container for every non-mail channel: `php:8.4-cli` running
the framework's own TLS server on port 18000 (HIL-921) —
`src/StandGatewayTlsServer.php`, an `AbstractTlsServer` whose connections are
the framework's `HttpClient` routed by its `HttpRouter`, started by
`bin/serve.php` (`framework/docker/stand-gateway/Dockerfile`, `CMD`). The image
installs only the extensions that server needs, in the daemon's order; the
framework itself is not copied in but mounted read-only from the stack at
`/hilos/framework`, and `bin/serve.php` loads classes by name from there and from
`src/`. There is no composer and no vendor directory, on purpose — a dependency
here would be a dependency to keep current for no gain — so a class the gateway
uses must not need a package.

A resident is a **route prefix**. `SmsRoutes::CHANNEL` is `sms`,
`TelegramRoutes::CHANNEL` is `telegram`, `OAuthRoutes::CHANNEL` is `oauth`,
`ModelRoutes::CHANNEL` is `model`, and every route of the resident hangs under
`/<channel>/…`, registered by an exact method and path through
`src/GatewayRoutes.php` — the routes of ONE connection.
The gateway builds them for every connection it accepts
(`StandGatewayTlsServer::onCreateClient()`), with a router of their own, and
every resident registers on them: a behavior a spec
dictated is played out on the connection that carries the call
([Behavior Handles](#behavior-handles)), so a route has to know which connection
that is, and it knows from the routes it was registered through. The core hands a
route the request body as a raw string; `GatewayRoutes` turns it into fields in
one place, the wrapper every route of every resident goes through — JSON when the
body is JSON, a form otherwise, the query string merged in beneath — so a handler
takes an array of fields and, when it needs them, the request headers. A provider
route is registered together with its key: the value of the call a declared
behavior is scoped to.

Why one container rather than one per channel (HIL-653): a channel here is a
class beside `TelegramRoutes` and `SmsRoutes` plus an endpoint in the stack's
compose — not a new service, a new port and a new way to read what it delivered
(`src/StandGatewayTlsServer.php`, class docblock). The exact recipe is
[Adding the Next Resident](#adding-the-next-resident).

Two things about the house are deliberate and easy to "fix" by mistake:

- **The image version tracks the repository's PHP**, not the reference mock's.
  The gateway runs the framework's own classes, written in the same PHP as the
  rest of the repository, and an older image would not parse them — the
  container would not come up at all (`Dockerfile`, header comment).
- **Only the house's own routes are unprefixed**, and there are three:
  `GET /test/health`, which the compose healthcheck waits on before it starts the
  daemon; `POST /test/reset`, which wipes the whole store; and
  `POST /test/behavior`, which dictates how a provider route answers. They are
  about the gateway, not about any resident — the behavior levers work the same
  on every provider route, so they are the house's — and a resident does not add
  to them. What a resident has to be told lives under its own prefix instead:
  the local model's answer is dictated at `POST /model/test/answer`, beside the
  model's provider route and not beside the house's handles, because what the
  model SAYS is that resident's business while how any answer LEAVES is the
  house's.

## The Halves of a Resident's Routes

A resident's routes come in halves, registered side by side in the one
`register()` of its routes class. Two of them for a resident the daemon calls,
three when the participant is a browser:

- the **provider half** — what the product calls, registered with
  `GatewayRoutes::provider()`. For Telegram that is
  `POST /telegram/checkSendAbility` and `POST /telegram/sendVerificationMessage`,
  what `framework/backend/Telegram/TelegramGatewayClient.php` posts. Each route
  names its **key** — the value of the call the spec coined itself, which a
  declared behavior is scoped to: `phone_number` for both Telegram routes, `to`
  for `POST /sms/send`. The local model's `POST /model/api/generate`, what
  `framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider.php` posts, is the
  one route keyed differently, below;
- the **test half** — what the spec calls, under `/<channel>/test/…`, registered
  with `GatewayRoutes::test()`. For Telegram that is
  `POST /telegram/test/reachable`, the one thing a spec cannot arrange any other
  way: a number nobody put on Telegram. For OAuth there are two:
  `POST /oauth/test/account`, which declares the world of the provider — which
  accounts exist over there — and `POST /oauth/test/expired-code`, which orders
  that an account's next code is born expired, one order being one code
  (HIL-926). For the local model it is
  `POST /model/test/answer`, which dictates the text the model answers with;
- the **page half** — what a BROWSER opens in the course of the product's work,
  registered with `GatewayRoutes::page()` (HIL-923). For OAuth that is
  `GET /oauth/<profile>/authorize` and the `POST` the form on it makes. It names
  no key and carries no lever, and neither is an omission: the call carries no
  value a spec coined, so there is nothing to scope a declaration to, and a
  declaration naming such a path is refused as `PATH_NOT_PROVIDER`. Nor is it a
  test route, because a spec does not call it — a person's browser arrives there,
  sent by the product. A spec that opens the screen itself, in the product's
  place, navigates with Playwright's `goto` on an address written from
  `STAND_GATEWAY_URL` — the one `goto` that `E2E-PAGE-GOTO` lets past `gotoPage`
  ([testing-strategy.md](frontend/testing-strategy.md#opening-a-page--gotopage-never-goto)).

`framework/docker/stand-gateway/src/TelegramRoutes.php`, `register()`, is the
sample for the first two halves, side by side; `src/OAuthRoutes.php` is the
sample for a resident that has all three. SMS has no test half, and that is
not an omission: there is nothing an SMS spec has to arrange up front.

**On the model, the two kinds of arrangement split cleanly.** The resident's
test half dictates the CONTENT of the answer — the raw text the model says, not a
parsed verdict, because what a demo reads out of that text is the demo's business
(`src/ModelRoutes.php`, class docblock). The house's levers dictate its FORM — a
status, a delay, a cut, a hold ([Behavior Handles](#behavior-handles)). One
dictation answers one call and dictations for one key queue, exactly as behaviors
do; a call nobody dictated an answer for is refused with
`503 {"ok":false,"error":"ANSWER_NOT_DICTATED"}` — a model on the stand says
what it was told and nothing else, and for the product that refusal reads as a
model that is not answering (the owner's decision, 17.09.2026).

**The model's key is a substring of the prompt, and that is the one departure
from "the key is a value of the call".** No field of a model call carries a value
the spec knows in advance: the product builds `prompt` from its own template
(`demo/chat/backend/Agents/ModeratorAgent.php`,
`buildMessageModerationMessages()`), and `model` is the same for every call —
keying by it would let a neighboring worker spend the answer. What the spec does
know is the string it put into the conversation itself — the text of a message,
a new name — and the prompt carries it verbatim, so the resident looks for it
there: the first announced key the prompt contains wins, the dictated answers'
keys first and the route's behaviors' keys after them (`Store::keyAnnouncedIn()`).
A key therefore has to be unique AND must not be a part of another spec's text.

The halves differ in whom they trust. The provider half checks what the real
service checks — the Telegram gateway refuses a call without a bearer token,
because a daemon that forgot its credentials has to fail on the stand rather than
in production; the SMS gateway checks nothing, because the generic provider's
default auth mode is `none` and a token here would guard nothing; the local model
checks nothing either, because a local model has no token and one checked here
would check what production does not have. The test half carries no credentials
at all: it is called by a spec, not by the product. The page half checks what the
real service would check of the request that brought the browser there — the
OAuth consent screen refuses an authorization request with no client, a callback
address that is not absolute, or a response type no provider answers, and
refuses it with a PAGE rather than a redirect, because a junk callback address is
nowhere to redirect to.

**Behavior is steered through the emulator's own HTTP handles, never through
the daemon's command channel.** A spec already holds an HTTP client —
`demo/chat/tests/e2e/helpers/gateway.ts` posts JSON to the gateway with a bare
`fetch` — and the test routes are the emulator's user interface. The daemon's
command channel is about the daemon; an external service is not configured
through it, and a handle that lived there would make the product carry test
wiring for a service it does not own. This is also the owner's requirement on
the epic (HIL-918): control over every emulator, and not through the CLI.

## Three Kinds of Resident

The formula so far — a prefix, a mail domain, and what is caught is read as a
letter — is the shape of ONE kind of resident, and it is the wrong shape for the
next two the house will take. What decides the kind is not the author's taste
but **who the emulator talks to, and who reads what comes out**: the daemon,
with a person reading the result; the daemon, with the calling code reading the
result; or the browser.

1. **A message channel.** The daemon sends something a person is meant to read
   — a code, a notification. The spec arranges the world through the test half
   and reads the outcome as mail
   ([below](#what-a-resident-catches-is-read-as-mail)). SMS and Telegram are
   this kind; Slack and WhatsApp, when they come, are too.
2. **An interlocutor.** The daemon asks and the answer comes back to the calling
   code; no person reads it, so there is nothing to forward and nothing to open
   in a mailbox. The spec DICTATES the answer up front through the test half and
   then asserts the consequence inside the product — the message that was
   moderated, the name that was changed. The local model
   (`framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider.php`) and the
   external one (`framework/backend/LLM/External/Chat/AsyncOpenAIChatProvider.php`)
   are this kind. The local model has moved in (HIL-925): prefix `model`, the
   completion protocol (`POST /model/api/generate`, answered in Ollama's
   envelope, of which the product reads only `response`), and the answer is
   dictated by the spec (`POST /model/test/answer`). The external one speaks a
   different protocol in the same role, and would move in as a second set of
   routes of the same resident; it has no leaf.
3. **A redirect through the browser.** The participant is not the daemon but the
   browser: it leaves for the emulator's page and comes back on the callback
   with a code, and the daemon then exchanges that code for a token over HTTP.
   There is nothing to catch and nothing to forward; what is proved is the round
   trip itself and the exchange behind it. OAuth is this kind and has moved in
   (HIL-923), and the three demos sign in through it (HIL-924).

**The third kind has TWO entities, and they do not live in one process.** The
provider is a resident of the house (`src/OAuthRoutes.php`, with every difference
between the providers it plays in `src/OAuthProfile.php`). The PERSON at its
window is a set in the spec's own folder
(`demo/chat/tests/e2e/helpers/oauth-user.ts`), and a spec is what gives the
orders — wait for the window, pick this account, confirm, refuse, walk away. The
world that person acts in is declared through the provider's test half from
beside them (`demo/chat/tests/e2e/helpers/oauth.ts`). Both files have twins in
`demo/polls/tests/e2e/helpers/`, beside the twin of `gateway.ts` they stand on.

The split is forced rather than tasteful: waiting for a window to open, pressing
a button in it and closing it can only be done by whoever is IN the browser, and
the house has no hands there. A server-side half of the person could drive the
screen only through a script on the page, and then the button would be pressed by
the page rather than by a person — the one thing an emulator of this kind exists
not to fake. The two stay apart in the spec's set for the same reason (the
owner's decision, 17.09.2026): merging the provider's world and the person back
into one file loses the distinction the kind is built on.

Name the kind before designing the resident. A leaf that copies the message
channel's formula for OAuth designs a letter that never exists — exactly the
mistake this page is written to prevent.

## What a Resident Catches Is Read as Mail

For a message channel, and only for one. The gateway carries no way to read what
arrived; every caught message leaves as a letter to the stand's Mailpit
(`src/MailForwarder.php`), so the one inbox a person already opens holds mail,
SMS and Telegram alike, read by the runner and by a person out of the same
place.

- **The addresses carry the channel and the recipient.** The sender is
  `<channel>@stand`, the recipient `<recipient>@<channel>.stand`
  (`MailForwarder::senderAddress()`, `recipientAddress()`): an SMS to
  `+15550001` arrives from `sms@stand` to `+15550001@sms.stand`, a Telegram code
  to `+15550001@telegram.stand`. Channel and number ride the address because
  that is what a mailbox filters and searches on — "my code" is told from
  somebody else's by who it was sent to, not by position in a list.
- **The subject is the message text**, on one line and cut to
  `MailForwarder::SUBJECT_MAX_CHARACTERS` (120), so a code reads straight off
  the mailbox list without opening the letter (`subject()`). The body is
  `Channel: <channel>`, `To: <recipient>`, `Sent: <time> UTC`, a `---` line,
  then the full text (`letter()`).
- **The forward happens BEFORE the gateway answers the daemon.** On a stand
  "delivered" has to mean "readable", so a relay that refuses becomes a refusal
  to the daemon — a `502`, which the SMS provider reads as transient and worth a
  retry — rather than a success nobody can check.
- **The mailbox is shared by every spec on the stand.** A spec names the
  recipient it waits for and coins one no other spec uses (`uniquePhone()` in
  `helpers/sms.ts`); "the newest letter" is somebody else's as often as not.
- **The relay is the stand's Mailpit**, named to the gateway service by
  `MAILPIT_SMTP_HOST` / `MAILPIT_SMTP_PORT` in each stack's compose. On the test
  stack Mailpit publishes no host port, on purpose; the runner reads it over
  `MAILPIT_URL` (`helpers/mail.ts`), and a person reads on the local or dev
  stack, where the UI is published on a host port each demo's README lists.

**There is no handle that lists what was delivered, and there will not be one.**
There was one, and HIL-653 removed it (`src/StandGatewayTlsServer.php`, class
docblock, "What was here before and is not any more"): a second viewer would
have nothing to draw from that the inbox does not already show, and a spec that
read the gateway's list would be reading a stand artifact instead of the
transport's end. A resident that catches something forwards it; it does not
keep it.

## State: One File Under a Lock, Wiped by a Handle

Whatever a spec arranges up front — which numbers are declared absent from
Telegram, the queues of declared behaviors, the world of the OAuth provider:
which accounts exist at it, and the codes and tokens it has handed out, and the
queues of the local model's dictated answers — lives in one JSON file under an
exclusive lock, `/tmp/stand-gateway-state.json` (`src/Store.php`, `PATH`). That is the whole
storage design, and it is enough: one runner, a few writes per suite.

The file was forced by PHP's built-in server, which re-entered the script for
every request and kept nothing in memory. Since the gateway moved onto the
framework's server (HIL-921) it is one long-lived process, so that reason is
gone. The decision is left as it stands: the file still works, and replacing it
with memory is not any leaf's work.

**The file is deliberately not a volume.** State that outlives the container
would make a spec's outcome depend on what an earlier run left behind, which is
exactly the class of flake a stand exists to remove (`Store.php`, class
docblock). What arrived is deliberately not in the file either — it left as a
letter — so the store holds only the arrangement.

**Wiping is a handle**: `POST /test/reset` forgets everything — declared
behaviors, the provider's world and the model's dictated answers with it
(`Store::reset()`). It is a whole-store wipe, so it is for a spec that genuinely
needs a clean slate and not for ordinary isolation: everything the store holds is
keyed by the value a spec coined (a number, an account id, the string a model's
prompt carries), and a unique value per test isolates it already. Calling reset
under parallel workers would clear state a neighboring spec is still using
(`helpers/telegram.ts`, `resetTelegram()`).

The rule for the next resident: **its state goes into the same file, under the
same lock, and is gone on the same reset.** A resident does not open a store of
its own, and the reset does not learn which resident's state to spare.

## The Emulator Speaks Real TLS

The emulator speaks the TLS the production peer speaks, and the address that
points the daemon at it keeps `https://`. Trusting it costs the emulator's CA in
the trusted store of the stand's containers — the production client verifies its
peer (`AsyncHttpClient::streamContextOptions()`, `verify_peer => true`), and the
stand does not relax that; the emulator earns the trust the way a real peer
would.

The reason is a defect that plain HTTP cannot show. HIL-732 exists because of
the TLS layer: on TLS the socket is announced readable as soon as protocol bytes
arrive, while the decrypted application bytes are not there yet — an empty read
means "no data yet", never "the peer is done"
(`framework/backend/API/AsyncHttpClient.php`, `processReceiving()`, the comment
above the buffer append). Over plain HTTP that window does not exist at all. An
emulator reached over `http://` therefore makes the one known defect of this
layer invisible: the instrument of observation removes the thing observed. That
is why the fork in `AsyncHttpClientTest` plays a TLS peer and not a plain one,
and why the stand cannot take that regression over until it speaks TLS itself.

The three demos reach the gateway as `https://stand-gateway:18000`
(`SMS_ENDPOINT_URL`, `TELEGRAM_GATEWAY_ENDPOINT_URL` in each demo's
`docker/docker-compose.{local,dev,test}.yml`; `CHAT_MODERATION_URL` on the chat
test stack; `OAUTH_ENDPOINT_URL` on the test stacks of all three).
`stand-gateway` is a network alias the gateway service carries in every stack,
and it is also the name its certificate is issued for: the daemon checks the
name of the peer it reaches, so the service name, which differs from stack to
stack, cannot be the address. One name means one certificate, and a new demo
does not reissue it.

The certificate is fixed and lives in the repository
(`framework/docker/stand-gateway/tls/`: `server.pem` is what the gateway
presents, `ca.pem` is what a caller trusts, `issue.php` reissues the pair by
hand). Trust is given by the environment of the calling container, never by a
switch in the client: the daemon and CLI services carry
`SSL_CERT_FILE=/hilos/framework/docker/stand-gateway/tls/ca.pem`, which OpenSSL
reads beside the system's certificate directory, so every other peer stays
trusted; the gateway's own healthcheck trusts `/app/tls/ca.pem` the same way;
the e2e runner, whose specs call the test half, carries
`NODE_EXTRA_CA_CERTS` with the same file, which Node adds to its roots. A
daemon without that trust fails the handshake with a named reason
(`AsyncHttpTlsHandshakeException`), which is exactly what a production peer
with a foreign certificate would cause.

For OAuth the BROWSER reaches the gateway too: it opens the provider's consent
screen at `https://stand-gateway:18000/oauth/<profile>/authorize`. Its pass to the
stand's certificate is the runner's `ignoreHTTPSErrors: true`
(`demo/*/tests/e2e/playwright.config.ts`), not `NODE_EXTRA_CA_CERTS`, which only
Node's own calls read. The daemon's half of the same trip — the exchange and
userinfo — is verified like any other call, through `SSL_CERT_FILE`.

The gateway listens on TLS only, and there is no switch for plain HTTP. A
resident's routes are served over it without doing anything: the transport is
the house's, not the resident's.

## Behavior Handles

A provider route answers the way a spec tells it to (HIL-922). The spec declares
the answer over HTTP, on the house's own route, before the product's call arrives,
and the declaration is spent on exactly one call. Four levers: the **status** the
call is refused with, a **delay** before the answer, a **cut** in the middle of
the answer, and a **hold** — keeping the connection open for a while after the
answer is complete.

**The wire.** `POST /test/behavior` with a JSON body:

```json
{"path": "/telegram/sendVerificationMessage", "key": "+15550001234", "status": 500}
```

`path` names a provider route of a resident and `key` the value of the call the
declaration is scoped to; `status`, `delayMs`, `cut` and `holdMs` are the levers,
each optional. Accepted — `200 {"ok":true}`. Refused —
`400 {"ok":false,"error":<code>}`, and the checks run in this order, so the first
mistake is the one named:

1. `FIELD_UNKNOWN` — a key that is not one of the six; this is what catches
   `delay_ms` written for `delayMs`;
2. `PATH_NOT_PROVIDER` — the path is not a provider route: a path nobody
   registered, a resident's test half, or a house route;
3. `KEY_REQUIRED` — the key is missing, not a string, or empty;
4. `STATUS_OUT_OF_RANGE` — the status is not an integer from 400 to 599;
5. `DURATION_INVALID` — `delayMs` or `holdMs` is not an integer of at least 0;
6. `CUT_INVALID` — `cut` is not a boolean.

The types are strict because the helper sends JSON only; a refusal fails the spec
where the declaration was made, rather than later on a provider that answered as
usual. A declaration of `path` and `key` alone is legitimate: it answers as usual,
which is how a spec writes "then normally" inside a sequence.

**Scoped to the pair of path and key, not to the channel's next call.** The chat
e2e suite runs fully parallel (`demo/chat/tests/e2e/playwright.config.ts`), and a
declaration for "the next call" would be spent by a neighboring worker's call. The
key is the value the spec coined itself — the number `uniquePhone()` produced, for
Telegram and SMS alike — which isolates a declaration exactly as it isolates a
number declared absent ([State](#state-one-file-under-a-lock-wiped-by-a-handle)).
Each provider route names its key when it is registered, because only the resident
knows which value of its call a spec coins.

**One declaration answers one call, and declarations queue.** Declarations for the
same pair are taken in the order they were made: three refusals make a provider
that fails three retries, and "500, then slow, then as usual" is three
declarations — there is no counter field.

**What each lever does**, and they combine in one declaration ("500 after two
seconds", "cut, then hold"):

- **status** — the resident's handler is not called: a provider that failed
  delivered nothing, and a letter in Mailpit for a refused call would be a lie a
  spec could read. The answer carries the status, `Content-Type: application/json`
  and the body `{"ok":false,"error":"STATUS_DICTATED"}`.
- **delayMs** — the answer is built when the call arrives (a letter, if any, is
  forwarded then), and its bytes leave that many milliseconds after the call was
  routed. The connection is read all the while, so a peer that gives up first
  takes it down at once.
- **cut** — the status line, the headers with the full `Content-Length`, and the
  first `floor(length / 2)` bytes of the body leave; then the connection closes.
- **holdMs** — after the last byte of the answer, whole or cut, the connection
  stays open that many milliseconds and then the gateway closes it; whatever
  arrives meanwhile is thrown away, and a peer that closes first ends it at once.

A call with a cut or a hold always ends its connection, even when the request
asked for keep-alive. A status and a delay leave the connection's policy alone,
and the next request on a kept-alive connection is answered without levers.

**How precise a deadline is.** A delay and a hold are deadlines the gateway's tick
checks every 10 ms (`bin/serve.php`, `LOOP_PAUSE_US`), never a pause inside a
handler: the gateway is one process serving every connection, and a handler that
stopped to wait would stall all of them — the very failure a delay exists to
imitate on ONE call. A deadline never comes early and comes at most one tick late,
so a spec measures only the lower bound.

The hold is not a nicety, and the number behind it was measured while
HIL-732 was being fixed (recorded on the epic, HIL-918): a counterpart that
closes the connection right after its answer gives a GREEN test on broken code
five times out of five; the same counterpart holding the connection for 50 ms
gives RED five times out of five. A peer that hangs up promptly hides the very
bug a peer that lingers exposes, so "hold the connection" is what makes a whole
class of read-loop defects testable at all.

The levers work on the local model's `POST /model/api/generate` like on any other
provider route, keyed by the same string its dictated answer is (see
[the halves](#the-halves-of-a-residents-routes)). Two consequences are the
spec's to know, and they are written into `dictateModelAnswer()`'s TSDoc rather
than changed in the house: a dictated status and a dictated answer on one key do
not combine — a refused call never reaches the resident, so the text would be left
behind for the next call — and a key announced ONLY by a behavior is gone once
the behavior is taken, so a delay with no dictated text is an undictated call and
answers 503 after that delay.

The handles are the house's, not one resident's: a status or a hold is dictated
the same way for every provider half, so a spec learns one arrangement and the
failed-provider scenarios are written once: they live in
`demo/chat/tests/e2e/tests/auth.spec.ts` (HIL-926) — a status, a delay and a cut
from the house, and beside them the order for an expired code, which no lever
gives because a provider refuses a code in a form of its own. The declaration is
`src/Behavior.php`, the queues live in `Store`, `GatewayRoutes::provider()` takes
the declaration on the call, and `src/StandGatewayHttpClient.php` plays the delay,
the cut and the hold out on the connection. A spec dictates through
`dictateGatewayBehavior()` in `demo/chat/tests/e2e/helpers/gateway.ts`; the
gateway's own mechanics are held to this contract by
`demo/chat/tests/e2e/tests/stand-gateway.spec.ts`.

## Adding the Next Resident

Seven steps, each with the sample to copy. A resident that needs an eighth is
telling you it is a different kind ([above](#three-kinds-of-resident)) — stop
and name it before writing.

1. **A routes class implementing `GatewayResident`** beside `src/SmsRoutes.php`
   and `src/TelegramRoutes.php`, with a public `CHANNEL` constant. The constant is
   the route prefix and, for a message channel, the mail domain a caught message
   is read under.
2. **Every half in the one `register(GatewayRoutes $routes)`**: the provider half
   under `/<channel>/…` through `provider()`, naming the key of each route, and the
   test half under `/<channel>/test/…` through `test()`. No test half when there is
   nothing to arrange up front, as with SMS. For an interlocutor the test half is
   where the spec dictates the answer (`POST /model/test/answer`). A resident a
   BROWSER visits registers its screens through `page()` instead — no key, no
   levers, and no declaration can name such a path.
3. **One entry in the `$residents` list of `StandGatewayTlsServer`'s
   constructor** — `new <Channel>Routes()` beside the ones that are there.
4. **The class in namespace `Hilos\StandGateway`, one class per file, named as
   the file.** `bin/serve.php` loads classes by name from `src/`, so there is no
   list of files to add to — and a class from outside `src/` and the framework
   is not found at all.
5. **The service and the endpoint in every stack's compose** —
   `demo/{chat,tasks,polls}/docker/docker-compose.{local,dev,test}.yml`. The
   gateway service is one per stack, built from
   `framework/docker/stand-gateway`, mounting the framework read-only, carrying
   the network alias `stand-gateway`, with `MAILPIT_SMTP_HOST` pointing at that
   stack's Mailpit; a resident adds no service, only the daemon's endpoint for
   it — `https://stand-gateway:18000/<channel>…` — and, if the daemon needs one,
   a credential (`TELEGRAM_GATEWAY_TOKEN`).
6. **A daemon environment variable that swaps the production address for the
   emulator's** — the way `SMS_ENDPOINT_URL` and `TELEGRAM_GATEWAY_ENDPOINT_URL`
   do. The switch is one address in the daemon's configuration, not a DNS trick
   and not a code path that knows it is on a stand. Where the product reads the
   address per role, the role's key is the one to set: the local model is
   `CHAT_MODERATION_URL=https://stand-gateway:18000/model` on the chat test stack,
   not the global `LLM_LOCAL_URL`, which the bot and the context analyzer fall back
   to as well — a global address would move them onto the emulator silently. An
   address the environment gives one role reaches that role's agent only if the
   project's settings layer, finding its own URL setting empty, falls back to the
   profile env resolved rather than to the global address. Chat's does since
   HIL-927 (`demo/chat/backend/Environment/ChatLlmProfileOverrideSource.php`);
   before it the stand's address was lost on the way, silently — check that layer
   before trusting a per-role address in a new project. Where
   the product builds its addresses from a recipe rather than reading one whole,
   the variable is a BASE and the emulator's paths are what hang off it:
   `OAUTH_ENDPOINT_URL=https://stand-gateway:18000/oauth` redirects the OAuth
   PRESETS (`framework/backend/Auth/OAuth/OAuthProviderPreset.php`), each of which
   then builds `<base>/<profile>/authorize|token|userinfo`, while a provider a
   project configures by hand keeps its own addresses. The
   address keeps `https://`, and a client that could not speak TLS is taught to
   (the local model's was, in HIL-925), because the gateway will not speak plain.
7. **A spec helper** beside `demo/chat/tests/e2e/helpers/sms.ts` and
   `telegram.ts` (and their twins under `demo/polls/tests/e2e/helpers/`): for a
   message channel, a `waitFor<Channel>…()` that reads the letter by recipient,
   and one function per test handle; for an interlocutor, the one function that
   dictates the answer (`dictateModelAnswer()` in `helpers/model.ts`), with the
   key coined by `modelKey()` of the same helper. A demo whose product PARSES the
   answer wraps the answer's shape in a helper of its own — chat's is
   `dictateModerationVerdict()` in `helpers/moderation.ts` — because the gateway
   hands back raw text and belongs to no demo. The price is paid by every spec:
   each chat spec that sends a message or renames a user from the profile
   dictates a verdict BEFORE the action, and one that does not reads "Moderation
   unavailable" instead of the outcome it was written for. The
   behavior levers are not a test handle of the resident: a spec dictates them
   through `helpers/gateway.ts`, and the resident brings no helper of its own for
   them.
   **A resident of the third kind brings TWO helpers, and they are not to be
   merged**: the world of the provider (`helpers/oauth.ts`) and the person acting
   at its window (`helpers/oauth-user.ts`). The first is arrangement, the second
   is somebody the spec gives orders to, and one file holding both would read as
   an emulator that presses its own buttons.

What a resident does NOT bring:

- its own container, its own port, its own image;
- its own way of reading what it delivered — a list route, a file, a database
  read from the spec;
- its own state file or its own reset;
- a new in-process stub in the framework "for the unit tests". That is the
  sunset rule, next.

## A Stub Is a Sunset, Not a Tool

Decided by the owner on 13.09.2026: after the emulator, an in-process test stub
is not a legitimate instrument with a ceiling ("fine for units, just not for
transport") but a **sunset**. The owner's words, whole: "Dying out. Without
haste, little by little, everything has to be rewritten onto the emulator. How
to shape that, I do not know." Everything moves, direction by direction, and the
pace is part of the decision; the shape was left to the interview, and what
follows is its answer.

Three things follow, and they are the whole of the rule:

- **No new test stub is added.** A direction that needs to be proved on the
  stand gets a resident, by the recipe above.
- **An existing stub leaves with the leaf that touches its direction.** This
  page raises no "rewrite the stub" leaves — a queue of them would contradict
  "without haste" outright — and the marker on the stub is what makes the rule
  work when a leaf finally reaches that direction.
- **The marker lives on the code site.** A sunset stub carries one line in its
  class docblock, `SUNSET: <what replaces it> — <HIL-key or "no leaf yet">`, by
  the convention in
  [code-style/scaffold-markers.md](code-style/scaffold-markers.md): the reader
  who opens the stub has not read this page, and the intent has to be where the
  next sweep — or the next author about to lean on the stub — is looking.

The narrowing, named out loud: "everything" means the **test** stubs. There is
a second breed of stub in the code, and it does not sunset. `StubSmsProvider`
(`framework/backend/Sms/StubSmsProvider.php`, with
`Sms/Delivery/StubSmsDeliveryAttempt.php` beside it) is the auto provider when
no gateway endpoint is configured — it impersonates not a provider but the
ABSENCE of one, so that a project which has not bought a gateway yet runs its
login and notification flows instead of failing on them. That is product
configuration of an installation without a gateway, it lives in production, and
it carries no marker.

The eight directions, and where each stands. This table is also the epic's map:

| Direction | Today | Tomorrow | Leaf |
|---|---|---|---|
| Mail — `framework/backend/Mail/SmtpMailTransport.php` | Mailpit, real SMTP | — | closed |
| SMS — `framework/backend/Sms/HttpSmsProvider.php`, driven by `GenericHttpSmsProvider.php` and its descriptor's defaults | the stand gateway, `/sms/send` | — | closed |
| Telegram codes — `framework/backend/Auth/CodeChannel/TelegramCodeChannel.php` through `Telegram/TelegramGatewayClient.php` | the stand gateway, `/telegram` | — | closed |
| OAuth — `framework/backend/Auth/OAuth/HttpOAuthProvider.php` | the emulator, `/oauth/<profile>`; the three demos sign in through it | — | closed (HIL-923, HIL-924) |
| Code delivery — `framework/backend/Auth/Verification/LogVerificationDeliverer.php` | writes the code to the log, sunset | delivery through the stand | no leaf yet; leaves with whatever touches it |
| Local model — `framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider.php` | the stand gateway, `/model`; chat moderation asks it | — | closed (HIL-925 the channel, HIL-927 moderation) |
| External model — `framework/backend/LLM/External/Chat/AsyncOpenAIChatProvider.php` | nothing | an emulated model | no leaf yet |
| Web Push — `framework/backend/Push/WebPushRequestFactory.php`, `Push/Delivery/PushEndpointSend.php` | nothing | does not settle by address substitution | a separate interview of HIL-918, [below](#what-the-house-cannot-house-yet) |

## What the House Cannot House Yet

Two honest lines, so the gap is found here and not at the moment someone tries
to move in.

**Web Push does not settle by substituting a base address.** The endpoint a push
goes to is issued by the BROWSER when it subscribes, and the daemon sends to
whatever it was handed (`framework/backend/Push/WebPushRequestFactory.php`,
`framework/backend/Push/Delivery/PushEndpointSend.php`); there is no configured
base address to point at the stand, so the recipe above has nowhere to start.
It needs a different move — one the epic (HIL-918) has sent to an interview of
its own. This page decides nothing about it.

**Slack and WhatsApp are not in the code** — no class, no channel, no setting.
The house takes them by the recipe above the day a leaf brings them, as message
channels; until then they are not work, and nothing here is scaffolding for
them.
