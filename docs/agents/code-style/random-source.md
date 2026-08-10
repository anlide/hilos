# Random Source

Read this before drawing a random value in backend PHP, and whenever the
`RANDOM-SOURCE` guard fails on a call you added.

## Core Rule

`RandomHelper` has two axes, and the caller picks one by what the value is for.

| Axis | Methods | On a refused entropy source |
|---|---|---|
| secure | `secureBytes()`, `secureHex()` | throws `Random\RandomException` |
| tolerant | `bytes()`, `hex()`, `integer()` | falls back to `mt_rand()` |

- A value anybody outside must not be able to guess — a session token, a
  connection identity, an OAuth state, a recovery or invite token — is drawn from
  the **secure** axis, and the refusal is propagated, not swallowed.
- A value that only has to be unlikely to collide — a correlation id, a temporary
  directory name, an emitter identity, a demo user's number — may be drawn from
  the **tolerant** axis, and only from a file the `RANDOM-SOURCE` rule lists.

Inside the helper, `random_bytes()` is called in exactly one place —
`secureBytes()` — and `bytes()` catches that method's refusal instead of asking
the source a second time, so the two axes cannot drift apart on what "the secure
source" means. `random_int()` is called only by `integer()`; there is no
`secureInteger()`, because no secret is drawn as an integer today and a method
with no caller is a method nobody checks.

Calling `random_bytes()` / `random_int()` straight, without the helper, is a
legitimate third form and several secrets use it — the WebAuthn challenge, the
OAuth state nonce, the verification token. It is safe by construction: there is
no fallback to degrade to, the refusal simply travels. What it does not get is
the inventory: the `RANDOM-SOURCE` rule reads calls to the helper, so a direct
call is invisible to it. Prefer `secureBytes()` / `secureHex()` in new code, so
one grep answers where the secrets come from.

## Why the split

`bytes()` used to answer a refused source with `mt_rand()` for every caller, and
two secrets rode on it: the session token and the WebSocket accept key, both
minted in the master on the 101. A node that could not open `/dev/urandom` — the
prosaic way there is file-descriptor exhaustion under load — kept serving,
handing out guessable session tokens, and nothing about it looked wrong from the
outside. That is the failure this split makes impossible: on the secure axis the
value is never handed out at all.

## What a refusal does

A refusal is not recovered from — it ends the connection and then the node:

1. `WebSocketClient::handleHandshake()` marks the connection for closing and lets
   `Random\RandomException` travel on. Both mints run before the response is
   assembled, so no half-sent handshake exists: neither the 101 nor the welcome
   frame is written, the client sees a dropped socket and reconnects.
2. The manager catches it wherever it entered client code — the epoll read
   callback `onClientRead()`, which reads a connection in the normal case, or
   `tickServers()`, which sees the bytes that arrived between the two — logs one
   `error` line with the reason, and sets `shouldExit`. The catch stands ahead of
   the read callback's catch-all, which would otherwise log the refusal as one
   more broken connection and leave the node serving.
3. The next loop iteration takes the path SIGTERM already takes: the cluster hears
   the departure, every server prepares its shutdown and closes its clients.

An `exit()` from under the exception is not used: it tells no worker and closes no
client. A single connection refused on a living node is not used either — a node
without entropy cannot mint the next secret any better than this one.

## The list, and why it is not a baseline

`RANDOM-SOURCE` inventories **every** caller of the tolerant axis: a call from a
file the rule does not name is a violation, wherever it sits. The list is a
constant in `framework/tests/CodeStyle/Rule/RandomSourceRule.php`, not a record in
[baseline.txt](automated-checks.md) — a baseline entry is debt, it names the leaf
that pays it off and the file may only shrink, while these callers are a standing
choice a new CLI command may legitimately join.

The rule watches no zone and no method name on purpose. The next secret will not
appear where it is expected, and a rule that guarded only the places today's
secrets sit would let it through on exactly the inattention that let the old hole
in. The price is accepted and known: a new correlation id costs a line in the
list, written by the author who can also say why the value needs no secrecy.

## Workflow

1. Ask what the value protects. If a stranger guessing it gains anything, it is a
   secret.
2. A secret: call `secureBytes()` / `secureHex()`, add `@throws RandomException` to
   the docblock, and propagate it up the chain rather than catching it — the
   handshake path already ends in the node's graceful stop.
3. Not a secret: call `bytes()` / `hex()` / `integer()` and add the file to
   `RandomSourceRule::ALLOWED_PATHS`, with the reason in the calling file's own
   docblock.
4. Run `composer run test:framework:unit`, which carries the guard, its fixtures
   and this document's route.
