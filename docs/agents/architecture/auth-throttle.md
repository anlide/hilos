# Auth Throttle (Anti-Abuse Layer)

Read this before guarding a new action against brute force, before answering
"what does Hilos do about password guessing", and before proposing a captcha,
a challenge step, or any other punishment past the end of the ladder. The
machinery is `framework/backend/Auth/Throttle/`; the seam that asks it is
`Hilos\Core\Page\PageSignalRouter`.

## Core Rule

An expensive anonymous action is judged inside the action dispatch, before its
handler runs. The framework supplies three things and no more: window counters
per key, a ladder of escalating blocks, and a refusal the client already knows
how to render. Which actions are guarded is declared by the host that owns
them — a page or an agent — never by this layer.

Do not make a worker wait out a refusal. A worker is single-threaded, so a sleep
would stall every connection it serves; a guarded action is refused at once,
parked until the verdict lands, or let straight through.

## The Key: Scope, Identity, Action

A throttle key is the triple `(scope, identity, action)`. There are exactly two
scopes (`ThrottleScope`): `ip`, keyed on the client address and shared by
everyone behind one NAT, and `session`, keyed on one browser. Which address counts
as the client's is a configured decision behind a proxy — see *Which Address The IP
Scope Counts*.

An action is keyed once per scope, and **both are counted**: passing one limit
does not excuse the other, and a refusal by either refuses the action. An IP is
allowed more attempts than a session on purpose — the IP limit is a ceiling on a
crowd, the session limit a ceiling on one person. For the same reason a
successful authentication clears the `session` scope and **never** the `ip`
scope: one abusive browser must not lift the IP-wide pressure it created.

The session identity is the sha256 digest of the session token, never the token
(`ThrottleIdentity::forSession`, the one place that recipe exists). The action
payload is written to the analytics journal verbatim, so a raw token riding on it
would become a replayable credential sitting in a table; the digest keys the same
counter and cannot be presented as a session.

A scope with nothing to key on is dropped rather than keyed on a placeholder: one
shared empty identity would let strangers spend each other's budget.

## The Ladder And Its Forgiveness

`ThrottlePolicy` holds the numbers and no state. Attempts on a key are counted in
a fixed window; a count past that scope's limit raises the key one ladder step,
and the step says how long the key is blocked. The default ladder is 30s, 2m,
10m, 1h (`ThrottlePolicy::FALLBACK_STEPS`, overridable — see *Configuration*).

**The ladder does not run off its own end.** A key that breaches again while it
is already at the last step stays there, refused for the longest configured
duration. There is no step beyond the last one — see *No Captcha, By Decision*.

A blocked key that keeps knocking is answered *before* anything is counted, so an
abuser can neither push its window count up nor escalate the block further by
hammering a door that is already shut.

Forgiveness is a day of quiet: a level cools back to zero after
`ThrottlePolicy::LEVEL_COOLDOWN_SECONDS`, and that number is **deliberately not
configurable**. The cooldown is what makes the ladder a memory of abuse rather
than a permanent record, and a deployment that shortened it to minutes would hand
a patient client its ladder back for free.

## Where The Layer Answers

In the action dispatch, at `PageSignalRouter::deferForThrottleVerdict()`. It gives
one of three answers, and the cheapest question is asked first:

1. **Not covered** — the layer is off, the action is not on the host's list, or
   there is no scope that can be keyed. The action goes straight through, and
   nothing is counted.
2. **Already blocked** — this worker's replica of the counters shows a block in
   force on one of the action's keys, so it raises `ActionRateLimitedException` at
   once and the agent is never asked. The block was written to the runtime
   collection when it was decided, so a blocked client costs no signal, no wait
   and no database read.
3. **Keyed, not blocked** — the action is parked as a `DeferredAction` and one
   check per key goes to the throttle agent. The first refusal settles it; the
   action runs only once **every** key has allowed it.

Two orderings are load-bearing:

- **Parking happens before the access-level and action-auth guards.** Brute force
  must not be able to learn from those guards which accounts exist, and a refusal
  it never waits for is a refusal it can repeat.
- **The identity wait is the outer of the two waits.** A throttle key is minted
  per session, so an action from a connection this worker has not been told about
  yet is held first; keying it earlier would count a signed-in person's attempts
  against the anonymous bucket.

A verdict that misses its deadline releases the action and **runs it**
(`releaseExpiredDeferredActions()`): a missing verdict is this server's failure — a
dropped signal, a stopped agent — and not evidence against the client. Blocks in
force do not leak through that door, since answer 2 refuses them without parking.
A parked action resumes into the identical steps it was stopped before.

## Who Owns What

`Hilos\Auth\Throttle\Agent\AuthThrottleAgent` is the per-node truth source of the
`hilosAuthAttempts` runtime collection and its only writer. A worker reads its own
replica and asks the agent whenever it cannot settle an attempt from it; all the
arithmetic and the durable write live in the agent, so a worker never reaches the
database on the auth path.

The state has two halves and they survive differently:

- **Runtime counters** hold the windows and the block in force. They die with the
  process, and windows are deliberately not persisted — losing an in-flight window
  costs an abuser a few attempts, not a block.
- **Durable blocks** live in `hilos_auth_block`
  (`Hilos\Database\Entity\Item\AuthBlock`), one row per blocked triple under a
  unique index. `onStart()` replays every block still in force into the counters;
  without that replay, restarting the daemon would be a way to have a block
  forgotten.

An escalation writes the runtime row first and the database second, so a database
that is slow or down delays the *record* of the block rather than the block itself.
A sweep on `onTick()` retires quiet counters and deletes rows whose block was
served and cooled, so the table cannot grow by one row per key ever blocked.

A project activates the layer by declaring `HilosFeature::AUTH_THROTTLE`, and owes
exactly two things: the throttle agent pair and the block table. The counters are
mounted by the framework itself (`AuthThrottleFeature::mount()`) because they are
read in every worker and written in one: a project mounting them by hand would
declare the feature twice, and a key mistyped in the second place produces a guard
that reads an empty collection and lets everything through. The layer has no page
and no admin surface.

## Declaring The Guarded Doors

`THROTTLED_ACTIONS` is a constant on the action host — `AbstractPage` and
`AbstractAgent` both default it to `[]`, so an empty list opts the host out.
**The list belongs to whoever declares the action, not to this layer.** The
framework's own sign-in doors are listed on
`Hilos\Auth\Library\AbstractUsersLibraryAgent`; a project page lists its own.

The criterion: guard the doors that **guess a secret**, and the doors that make the
server **spend something on a stranger's say-so** — an email, an SMS, a password
hash, a registration reservation. Reads stay off the list, with the exception that
proves the rule: an action answering whether an account exists is what an enumerator
wants, and the list is the whole of what keeps that answer expensive. Actions
requiring a signed-in session are absent on purpose — nothing there to brute force.

## What The Client Sees

A refusal is `ActionRateLimitedException`: error code `rate_limited`, HTTP 429
semantics, and `retryAfter` in seconds. The dispatcher carries both onto the wire
in `PageActionErrorSignalData`, which the frontend parses as the `action_error`
payload (`actionErrorSignalDataSchema` in
`framework/frontend/core/src/protocol/envelope.ts`).

The refusal has **no screen of its own**: it travels the ordinary action-error path
every failed action uses. A denial that arrived without a number still denies —
dropping a decision because a hint went missing would let a blocked key through —
and the client is told the shortest wait the ladder can impose instead.

## Configuration

Every number is an env value with a default. The source of truth is
`Hilos\Constants\EnvConstants`; this table is a map to it, not a second copy.

| Key | What it sets | Default |
|---|---|---|
| `HILOS_AUTH_THROTTLE_ENABLED` | whether the layer refuses anything at all | on |
| `HILOS_AUTH_THROTTLE_WINDOW` | window length in seconds | 60 |
| `HILOS_AUTH_THROTTLE_MAX_SESSION` | attempts one session may make on one action per window | 10 |
| `HILOS_AUTH_THROTTLE_MAX_IP` | attempts one IP may make on one action per window | 30 |
| `HILOS_AUTH_THROTTLE_STEPS` | ladder, comma-separated seconds | `30,120,600,3600` |
| `HILOS_AUTH_THROTTLE_VERDICT_TIMEOUT_MS` | how long a parked action waits for its verdict | 1000 |

Each number is clamped where it is read, never trusted: a window of zero would make
every attempt the first of a fresh window, and a limit of zero would refuse
everybody's first attempt. `ThrottlePolicy::fromEnv()` falls back to *off* when there
is no environment at all, which is the no-configuration case and not the deployment
default. The test environment turns the layer **off** deliberately
(`demo/chat/tests/.env.example`) — suites request codes far faster than a human
would, and counters carried across a run would make every test depend on the ones
before it.

### Which Address The IP Scope Counts

The `ip` scope is only as good as the address it is keyed on, and behind a proxy
that address is the proxy. `HILOS_TRUSTED_PROXIES` names the networks allowed to say
otherwise: a comma-separated list in CIDR notation, a single address written as
`/32` or `/128`, host names not accepted (resolving one would block the master's
accept loop). It is empty by default.

There is no wildcard, and `0.0.0.0/0` is not the missing one: it is refused by the
parse, as is any entry whose prefix is zero bits long — `::/0` and `10.0.0.0/0`
alike, since the prefix decides and not the address. A list holding nothing else
collapses to the empty one, which counts every connection by its TCP peer: one
throttle key for everyone behind the proxy, the cost spelled out below. Name the
network your proxy connects from, however small; if that is one machine, write it
as `/32`.

The rule is one sentence, and it is applied once per connection, on the 101:
**a peer inside one of those networks names the visitor through `X-Real-IP`;
every other peer names only itself.** "Every other" is literal — the list is empty,
the peer is outside it, the header is absent, or its value does not parse as an
address: all four answer with the address of the TCP peer, which is the behavior a
deployment had before this variable existed. Only `X-Real-IP` is read, and only
because nginx overwrites it wholesale; `X-Forwarded-For` is not read at all, since
nginx *appends* to it and the beginning of that chain belongs to the client.

The address settled here is the connection's for as long as it lives, and it is the
one both consumers see — this layer's `ip` scope and the analytics journal. Changing
the environment under a running daemon does not rewrite connections already open.

Nothing re-reads it afterwards, and that is why a change of address inside a live
connection is not something the framework can observe. A TCP connection's peer is
fixed from the accept to the close; IPv4 cannot turn into IPv6 inside it, because
the socket was created for one address family, and `::ffff:1.2.3.4` on a dual-stack
socket is the same address written another way. A visitor who moves between networks
does not change the address of a connection — the connection breaks, and the next one
is a new connection with a new journal row. Behind a proxy the visitor's address is
not visible at all: nginx holds two independent TCP connections, and the one we see
is nginx's own, unchanging because it is a separate connection between two servers.
The visitor's address arrives once, in a handshake header, and the framework does not
read it.

`hilos_analytics_ws_connection_ipv4_change` and `hilos_analytics_ws_connection_ipv6_change`
exist and are empty on purpose. They get a writer again only when an address of a
different nature is available: multipath TCP, which counts by a connection's paths
rather than by its peer and needs an explicit socket type on both ends, or the
visitor's own address read out of the handshake header. Neither is built on top of
today's code, so nothing here stands in the way of either.

What a deployment has to do:

- **Facing the network directly.** Nothing. The empty default is already correct,
  and it ignores an `X-Real-IP` a client sends of its own accord.
- **Behind your own nginx.** Put that nginx's network in the variable, and have it
  send the header (`proxy_set_header X-Real-IP $remote_addr;` — the three demo
  templates carry that line in their `/ws` location).
- **Behind someone else's proxy or a CDN.** Same variable, naming the network that
  actually connects to you. Unrolling a chain of proxies is nginx's own job
  (`set_real_ip_from` + `real_ip_header`), not the framework's; by the time the
  handshake is read there is one peer and one header.

**A deployment that sits behind a proxy and leaves this empty gives every visitor it
has the same throttle key.** The ladder then counts the whole audience as a single
client, and the first person to trip it blocks all of them — a misconfiguration
that looks exactly like a working one until the day it refuses everybody. An entry
that cannot be parsed is dropped and logged once per process, for the same reason:
a narrowed list keeps serving traffic and says nothing about itself.

## No Captcha, By Decision

Owner's decision of 2026-08-22, taken on the acceptance of HIL-420 and written down
here by HIL-660. It has three parts and all three are binding.

1. **Hilos does not ship a captcha.** No provider, no keys, no widget in any of the
   three frontends, no step in the ladder, and no env value that would configure
   one. Outside this section, nothing under `framework/`, `demo/` or `docs/` so much
   as names the thing — and that emptiness is the intended state, not an omission
   waiting to be filled.
2. **Hilos does not forbid one.** A project built on the framework may add its own.
3. **There is no extension point in the ladder for it.** A project's captcha lives on
   the project's side — its own action, or its own screen in front of the call — and
   the verdict mechanism has no hook to receive it. Do not describe one as if it did:
   a seam named in a document and absent from the code is the same promise this
   decision was written to retract.

## What Else Limits Abuse

Three neighbours limit abuse without belonging to this layer. Their mechanics are
theirs to document; these are signposts only.

- **Attempt ceiling on one-time codes** — a wrong code that reaches the ceiling voids
  the challenge (`Hilos\Auth\Verification\VerificationService`, refusal
  `VerificationRejectReason::ATTEMPTS_EXHAUSTED`). See
  [verification-codes.md](verification-codes.md).
- **Two challenges with separate ceilings, and a single-use letter** — a magic link
  and its companion code answer independently, and answering either voids the other
  (`Hilos\Auth\MagicLink\MagicLinkService`).
- **One registration in flight per browser** — a submitted registration holds its
  identifier for the session that started it, and the first proof of the address
  wins it (`Hilos\Auth\Registration\RegistrationReservationService`).

## Workflow: Guarding A New Action

1. Decide whether the action qualifies: reachable without a session, and it either
   guesses a secret or spends server resources on a stranger's word. If it needs a
   session, stop — it is not a brute-force door.
2. Add its name to `THROTTLED_ACTIONS` on the host that declares the action, and say
   in that constant's PHPDoc which half of the criterion it meets.
3. Change nothing under `framework/backend/Auth/Throttle/`. The layer is keyed by
   action name and needs no registration.
4. If the action authenticates a session, make sure the flow reports it
   (`ThrottleGate::reportAuthenticated()`), or that session's counters are never
   forgiven on success.
5. On an agent host, run the topology validation: `THROTTLED_ACTIONS` is checked
   against that agent's own `AGENT_ACTIONS`, so a name it does not own fails at
   startup rather than guarding nothing in silence.

## Anti-Patterns

- **Adding a tier of a different kind past the end of the ladder** — a challenge, a
  captcha, a manual review. There is nothing beyond the last step, by decision. A
  ladder is durations and only durations: to refuse harder, lengthen the last one or
  append another to `HILOS_AUTH_THROTTLE_STEPS`.
- **Counting attempts from a worker.** A worker holds a replica, not the truth;
  writing it there changes one process's memory and nothing else. Send the check to
  `AuthThrottleAgent` and let the verdict come back.
- **Moving a host's `THROTTLED_ACTIONS` into the throttle layer "so nobody forgets
  it".** The list is a property of the surface that declares the actions; a layer
  holding it would need editing every time a project adds a door. Declare it on the
  host — that is what the constant is for.

## Validation

`composer run test:framework:unit` — the window and ladder arithmetic
(`AuthThrottleLadderTest`), the deferred-action pool
(`PageSignalRouterThrottlePoolTest`), the agent-action guard rails
(`AgentActionRailsTest`), the trusted-proxy list and the address the handshake
settles on (`TrustedProxiesTest`, `WebSocketClientHandshakeClientIpTest`), and the
markdown rules that keep this file's links intact.
In `demo/chat`, `composer run test:unit` pins the topology snapshot that carries the
throttle agent, its signals and its test-only reset command.
