# The Stand's Emulated External Services

Read this before a spec needs an external service on the stand — adding a
channel or an emulator, choosing between a stub and an emulator, or looking for
where a caught message lands. The house is `framework/docker/stand-gateway`
(HIL-492, HIL-653); this page is the rule for living in it, written for the
author of the next resident rather than as a description of the two that live
there today. What a particular future resident looks like — behavior handles,
an OAuth provider, a model — is that leaf's own design (HIL-922, HIL-923,
HIL-925); this page says only what the house guarantees and
what a resident owes it. How to run the suites is [testing.md](testing.md), not
here.

## Why a Stand Emulates Rather Than Stubs

An in-process stub sends no byte. It proves what the code does with an answer it
was handed, and nothing about the road the answer travels: the request that was
built, the socket it went out on, the envelope that came back, the moment the
peer closed. `StubOAuthProvider` says it of itself — "No network, no sockets"
(`framework/backend/Auth/OAuth/StubOAuthProvider.php`, class docblock): its
authorize URL bounces the browser straight back to the SPA callback with a
canned code, and no exchange ever happens.

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
`TelegramRoutes::CHANNEL` is `telegram`, and every route of the resident hangs
under `/<channel>/…`, registered on the framework's router by an exact method and
path. The core hands a route the request body as a raw string; the gateway turns
it into fields in one place, `StandGatewayTlsServer::handler()`, which every
route of every resident is wrapped in — JSON when the body is JSON, a form
otherwise, the query string merged in beneath — so a handler takes an array of
fields and, when it needs them, the request headers.

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
- **Only the housekeeping routes are unprefixed**: `GET /test/health`, which the
  compose healthcheck waits on before it starts the daemon, and
  `POST /test/reset`, which wipes the whole store. They are about the gateway,
  not about any resident, and a resident does not add to them.

## Two Halves of a Resident's Routes

A resident's routes come in two halves, and they are registered side by side in
the one `register()` of its routes class:

- the **provider half** — what the product calls. For Telegram that is
  `POST /telegram/checkSendAbility` and `POST /telegram/sendVerificationMessage`,
  what `framework/backend/Telegram/TelegramGatewayClient.php` posts;
- the **test half** — what the spec calls, under `/<channel>/test/…`. For
  Telegram that is `POST /telegram/test/reachable`, the one thing a spec cannot
  arrange any other way: a number nobody put on Telegram.

`framework/docker/stand-gateway/src/TelegramRoutes.php`, `register()`, is the
sample, with the two halves side by side. SMS has no test half, and that is
not an omission: there is nothing an SMS spec has to arrange up front.

The two halves differ in whom they trust. The provider half checks what the real
service checks — the Telegram gateway refuses a call without a bearer token,
because a daemon that forgot its credentials has to fail on the stand rather than
in production; the SMS gateway checks nothing, because the generic provider's
default auth mode is `none` and a token here would guard nothing. The test half
carries no credentials at all: it is called by a spec, not by the product.

**Behavior is steered through the emulator's own HTTP handles, never through
the daemon's command channel.** A spec already holds an HTTP client —
`demo/chat/tests/e2e/helpers/telegram.ts` posts JSON to the test half with a
bare `fetch` — and the test half is the emulator's user interface. The daemon's
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
   are this kind; the first has its leaf (HIL-925).
3. **A redirect through the browser.** The participant is not the daemon but the
   browser: it leaves for the emulator's page and comes back on the callback
   with a code, and the daemon then exchanges that code for a token over HTTP.
   There is nothing to catch and nothing to forward; what is proved is the round
   trip itself and the exchange behind it. OAuth is this kind (HIL-923, and the
   demos' switch to it in HIL-924).

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

Whatever a spec arranges up front — today, which numbers are declared absent
from Telegram — lives in one JSON file under an exclusive lock,
`/tmp/stand-gateway-state.json` (`src/Store.php`, `PATH`). That is the whole
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

**Wiping is a handle**: `POST /test/reset` forgets everything
(`Store::reset()`). It is a whole-store wipe, so it is for a spec that genuinely
needs a clean slate and not for ordinary isolation: everything the store holds
is keyed by the value a spec coined (a number), and a unique value per test
isolates it already. Calling reset under parallel workers would clear state a
neighboring spec is still using (`helpers/telegram.ts`, `resetTelegram()`).

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
`docker/docker-compose.{local,dev,test}.yml`). `stand-gateway` is a network
alias the gateway service carries in every stack, and it is also the name its
certificate is issued for: the daemon checks the name of the peer it reaches, so
the service name, which differs from stack to stack, cannot be the address. One
name means one certificate, and a new demo does not reissue it.

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

The gateway listens on TLS only, and there is no switch for plain HTTP. A
resident's routes are served over it without doing anything: the transport is
the house's, not the resident's.

## Behavior Handles

A resident answers the way a spec tells it to. Four levers, all on the test half
and all dictated over HTTP before the product's call arrives: the **status** the
provider half answers with, a **delay** before the answer, a **cut** in the
middle of the response, and a **hold** — keeping the connection open for a
while after the answer is complete.

The last one is not a nicety, and the number behind it was measured while
HIL-732 was being fixed (recorded on the epic, HIL-918): a counterpart that
closes the connection right after its answer gives a GREEN test on broken code
five times out of five; the same counterpart holding the connection for 50 ms
gives RED five times out of five. A peer that hangs up promptly hides the very
bug a peer that lingers exposes, so "hold the connection" is what makes a whole
class of read-loop defects testable at all.

The handles are the house's, not one resident's: a status or a hold is dictated
the same way for every provider half, so a spec learns one arrangement and the
failed-provider scenarios (HIL-926) are written once. What exactly the handles
are called and how a dictated answer is scoped to one call is that leaf's design
(not in the code yet — HIL-922).

## Adding the Next Resident

Seven steps, each with the sample to copy. A resident that needs an eighth is
telling you it is a different kind ([above](#three-kinds-of-resident)) — stop
and name it before writing.

1. **A routes class** beside `src/SmsRoutes.php` and `src/TelegramRoutes.php`,
   with a public `CHANNEL` constant. The constant is the route prefix and, for a
   message channel, the mail domain a caught message is read under.
2. **Both halves in the one `register()`**: the provider half under
   `/<channel>/…`, the test half under `/<channel>/test/…`. No test half when
   there is nothing to arrange up front, as with SMS.
3. **One line in `StandGatewayTlsServer`'s constructor** —
   `new <Channel>Routes()->register($this->router)` beside the two that are
   there.
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
   and not a code path that knows it is on a stand.
7. **A spec helper** beside `demo/chat/tests/e2e/helpers/sms.ts` and
   `telegram.ts` (and their twins under `demo/polls/tests/e2e/helpers/`): for a
   message channel, a `waitFor<Channel>…()` that reads the letter by recipient,
   and one function per test handle.

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
| OAuth — `framework/backend/Auth/OAuth/HttpOAuthProvider.php` | the stub `StubOAuthProvider.php`, sunset | an emulated provider | HIL-923; the demos switch in HIL-924 |
| Code delivery — `framework/backend/Auth/Verification/LogVerificationDeliverer.php` | writes the code to the log, sunset | delivery through the stand | no leaf yet; leaves with whatever touches it |
| Local model — `framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider.php` | nothing | an emulated model | HIL-925 |
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
