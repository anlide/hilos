# Automated Rule Checks

Read this when a rule in `docs/agents/*` should stop depending on memory, when a
guard test fails on your change, or when you are about to add a machine-checkable
rule.

## What is checked by machine

| Rule id | Enforces | Canonical rule |
|---|---|---|
| `PHPDOC-FQN` | A docblock references a class by its imported short name, never by a leading-backslash fully qualified name. Covers the type position of `@throws`, `@param`, `@return`, `@var`, `@property`, `@property-read`, `@method`, `@extends`, `@implements` (generic arguments included), and the `{@see ...}` / `{@link ...}` cross-references. | [phpdoc.md](phpdoc.md) rules 9 and 12 |
| `RT-STATE-REACH` | `getStateCollection()`, `getStateItem()`, and `$this->stateCollection` are used only in files under `Database/` or `Runtime/`, whatever the caller's role. | [rt-state.md](../runtime/rt-state.md) |
| `ERROR-SUPPRESSION` | `@` silences a warning only under a `// warning-suppressed: <reason>` marker on the line directly above the call. Production roots only. | [error-suppression.md](error-suppression.md) |
| `RANDOM-SOURCE` | A secret is drawn from `RandomHelper::secureBytes()` / `secureHex()`, which throw when the entropy source refuses. The tolerant `bytes()`, `hex()` and `integer()`, which fall back to `mt_rand()`, are callable only from a file the rule itself lists — an inventory of every caller, not a guess at which zones hold secrets. Production roots only. | [random-source.md](random-source.md) |
| `MAGIC-REPEAT` | The same number is written twice or more in one file. Numbers inside a `const` declaration, inside the value of a keyed array entry — which is what takes a data catalog out of the rule, entry by entry — and the structural `0`, `1`, `2` are not counted. Production roots only. | [magic-values.md](magic-values.md) |
| `EMPTY-STRING-SENTINEL` | An empty string literal is minted where a value is absent: `??` falls back to it, a ternary branch hands it back, or a `match` `default` arm does. Inside the checked zone only, and unless a `// external-boundary: <reason>` marker on the line directly above names the outside source the value comes from. | [method-contracts.md](method-contracts.md) |
| `PAYLOAD-SENTINEL` | A payload reader mints a stub for a field that did not arrive: inside the body of `fromArray()` or `fromJson()`, `''`, `0` or `0.0` is fallen back to with `??`, handed back by a ternary branch, or returned by a `match` `default` arm. `?? null` and `?? []` are legal there, and a `// external-boundary: <reason>` marker on the line directly above legalizes one occurrence. Every root. | [method-contracts.md](method-contracts.md) |
| `WIRE-KEY-CASE` | A field key that crosses PHP → wire → TS is spelled camelCase. Two halves under one id: PHP judges a constant named in camelCase, TypeScript a constant named `<NAME>_FIELD` and the entries of an `as const` `*RowKey` map. A value that is a reference to another constant is judged where the key is spelled out. | [cross-layer-field-names.md](cross-layer-field-names.md) |
| `LINE-LENGTH` | A PHP line is wider than 150 characters. Width is counted in characters and not in bytes, so a multi-byte dash costs one column. A line inside a heredoc or nowdoc body is not checked: a break there would land in the string itself. | [line-length.md](line-length.md) |
| `E2E-PAGE-GOTO` | An e2e spec opens a page through `gotoPage()`, never through Playwright's `goto`, which waits for the document and not for the subscription's answer. TypeScript only; the `helpers/page.ts` that owns the wrappers is the one place the call is allowed. | [testing-strategy.md](../frontend/testing-strategy.md) |
| `DOC-ROUTE` | Every file of this catalog is mentioned by at least one `skills/*/SKILL.md`, or declines a route in itself and says why. A file that is both routed and declining is reported the same way. | [rule-authoring.md](../rule-authoring.md) |
| `DOC-LINK` | A local reference in the agent docs names something that exists. In a skill wrapper both a markdown link and a backticked path count as one; in a document only a markdown link does. | [rule-authoring.md](../rule-authoring.md) |

`MAGIC-REPEAT` is deliberately narrower than the document it enforces, and its
green run must not be read as "the magic-value rule is satisfied". It counts
numbers and no strings, because tokens cannot tell a wire key or a fragment of
SQL from a magic value; and it reads one file at a time, so the same value
declared independently by two classes is invisible to it. Both of those stay a
matter for review. A rule may be narrower than its document — it may never be
wider.

That last sentence is a requirement, not an observation, and `MAGIC-REPEAT` has
one place where it is not yet met: a minus is a token of its own, so a symmetric
pair such as `max(-1500, min(1500, $n))` reaches the rule as one value written
twice and is reported. No such site exists in the scanned roots today. It is
written down here rather than left to be rediscovered, because the way out of a
hit is to argue with the document, and an argument needs to know what the rule
actually does.

`EMPTY-STRING-SENTINEL` is narrower than its document in the same way, and on
purpose. It reads the three spellings that mint the literal — `??`, the branch of
a ternary after the colon, and a `match` `default` arm — and stops there. It says
nothing about `=== ''`: those comparisons are how legitimate input is checked, and
a machine ban on them would report the very code the document calls correct. It
also cannot see a bare `return '';`, which mints the same value out of a method
whose caller cannot tell it from data.

Reading a colon costs bookkeeping, because four other constructs spell one: a
named argument, a return type, the alternative syntax, and a `case` label. The
rule counts a colon as a ternary branch only while a `?` of the same bracket depth
is still open, and it tells that `?` from the one of a nullable type by what
stands before it — only a ternary follows something an expression can end with. A
`match` arm is told from a `switch` label the same way: by the double arrow, never
by the arrow alone, which is also how an array element is written.

`PAYLOAD-SENTINEL` overlaps `EMPTY-STRING-SENTINEL` on purpose and is not a
widening of it. It reads two more literals but only two method bodies, and the
scope is the point: the same `?? 0` is a decision about this object's own state
in a constructor and a decision about somebody else's frame in a payload reader.
Zero was deliberately left out of the empty-string rule for that reason — `?? 0`
occurs 66 times in the framework zone and 89 in the demos and suites, of which
only a quarter sit in a reader, so widening the older rule would have frozen
about 130 records that name no owed work. A line spelled `?? ''` inside a reader
is reported by both rules, which reads as two lines about one site and is the
honest report: both are owed, and both go away with the same edit.

The narrowness is the same kind the rules above have. It cannot see a bare
`return 0;` out of a reader, it does not read a helper the reader delegates to,
and it judges a method by its name, so a payload read in a method called
something else is invisible to it. It also asks nothing about agreement between
fields — that check belongs in the constructor and no token walk can make it.

It needs no zone, unlike the empty-string rule below: two method bodies across
every root is a small enough subject that its debt fits one baseline and stays
readable as a list of owed work.

`WIRE-KEY-CASE` judges the case of a key and nothing else — not the words, not
whether the two sides agree on them — and it sees only the keys declared in a
form it can recognize. Three blind spots follow from that, and a green run must
not be read as "the convention is kept":

1. **`FIELD_*` constants are outside the rule.** The form carries two meanings at
   once: `FIELD_REQUEST_ID = 'requestId'` names a key of the signal envelope,
   while `FIELD_ENDPOINT_URL = 'endpoint_url'` travels as the *value* of a field
   named `field`. Judging the form would report nineteen legitimate sites across
   the mail, SMS and push channels. The lexical test stays free of exceptions,
   and what it leaves out is a static set of envelope keys rather than anything
   that grows.
2. **A key written as a literal at the place it is used is invisible.** That is
   ownership, not case — [wire-key-ownership.md](wire-key-ownership.md) — and a
   key nobody declared has no declaration to judge.
3. **A `.vue` SFC is not read.** The TypeScript half parses `.ts` files, and no
   SFC declares a row-payload key today; a key that appears in one is a violation
   of ownership before it is ever a question of case.

The PHP half has the opposite gap, and the same sentence governs it: a rule may
never be wider than its document. It reads a camelCase constant name as the
declaration of a field key, which is what that name means in this repository —
but a camelCase constant holding a string that was never a key (`dateFormat =
'Y-m-d H:i:s'`, a URL, a format template) is reported all the same. No such site
exists in the scanned roots today, and the way out of a hit is to argue with the
document, so the argument needs to know what the rule actually does. The rename
that settles it is usually the honest one: a constant that names no wire key is
`UPPER_SNAKE` by the same convention that makes the camelCase name meaningful.

`LINE-LENGTH` reaches one step past what its exemption suggests, and the step is
worth knowing before you argue with a hit. The exemption is syntactic — a heredoc
or nowdoc body — because that syntax is what declares a long line to be content.
An ordinary multi-line quoted string declares nothing of the kind, so a long line
inside one is reported, and the only way out is to edit the string. Where the
whitespace is insignificant that is harmless: the analytics INSERT wraps its
column list and MySQL never notices. Where the newlines are data it is not, and
the cure is to move that text into a heredoc rather than to break it where it
stands.

The two markdown rules are narrower than their document as well, and each in a
way worth knowing before you argue with a hit.

`DOC-ROUTE` reads reachability as a direct mention and never expands it: a
wrapper that routes to another wrapper does not inherit that wrapper's files, and
a file reachable only through such a hop is still reported. A route an agent has
to derive is not a route it takes. The rule also judges this catalog alone. The
rest of `docs/agents/` has no owning mechanism to route from, and requiring one
there would decide the fate of documents this check does not own.

`DOC-LINK` judges the file part of a reference and nothing else: `page.md#section`
is checked as `page.md`, and whether the section exists is not asked. It also
stays silent on a bare path inside a document, however broken. A document names
files inside other roots constantly — `pages/keys.ts`, `Bootstrap/daemon.php` —
and reading those as addresses reported 46 legitimate mentions the day it was
tried. In a wrapper the same path *is* an address, because a wrapper exists to be
followed; that difference is the rule, not an oversight.

### Why the rule reads a zone and not the whole tree

`EMPTY-STRING-SENTINEL` is the one rule whose reach depends on the root it is
handed, and the choice is made in `CodeStyleGuardTest`, which is the only place
that knows which root is being scanned.

Inside `framework/backend` it fires only within a path zone — the signal spine
(`Core/Router`, `Core/Page`, `Core/Sync`, `Core/Agent`, `Core/Daemon`,
`Core/Table/DTO`, `Core/Source`), the whole of `Socket` and `Cluster/Peer/DTO`,
the operator and browser layers (`Core/Analytics`, `Core/Browser`, `Core/CLI`,
`Core/Feature`), the application subsystems (`API`, `Auth`, `Backup`,
`Database`, `LLM`, `Log`, `Mail`, `Notification`, `Pages`, `ProtectedMode`,
`Push`, `Runtime`, `Sms`, `Tables`, `Utils`) and `Hilos.php`. The framework is
cleaned one subsystem at a time: turned on across the root at once, its baseline
would become a list of exceptions rather than a list of owed work.

A zone entry matches a whole path segment wherever it sits, not a prefix, which
is what lets `Socket` be taken entire: `Socket/WebSocket/WebSocketFrameDTO.php`
and `Socket/Worker/WorkerDTO.php` sit BESIDE the `DTO` subdirectories the earlier
phases named, and no `Socket/Client`-shaped segment reaches them. The fixture
tree carries a file directly in a segment for exactly this reason — were the
match ever narrowed to a prefix, the fixture report would break before any
production file did.

Every other root — `demo/*/backend`, `framework/tests`, `demo/*/tests` — is judged
entire. A demo is an application on the framework and has no subsystem outside the
mechanism to phase, and a new demo root arrives through the glob with no
activation step, so a segment list would be forever chasing directories that
already exist. The zone is read relative to the scanned root, so the fixtures
repeat the segments of the framework zone to be judged by the same code, and a
fixture root of their own carries what the whole-root mode has to prove.

Inside the zone, a legal reading of outside input is named in place with a
`// external-boundary: <reason>` marker rather than frozen in the baseline: the
baseline records owed work, and a legal site owes none. The marker covers one
occurrence and its reason is mandatory; see
[method-contracts.md](method-contracts.md) for the convention and its cost.

The zone grows one phase at a time, and each phase pays off the records its
predecessor froze. Turned on everywhere at once, the rule would have reported
several hundred sites in one go: the baseline would then hold more exceptions
than the tree holds clean code, and a list that large is read as a mute list
rather than as owed work — which is the one thing the baseline must never become.

The checker is not a second source of truth. Each rule points back at the
document that owns it, and the failure line carries that path. Change the rule in
the document first; the check follows.

## How to run it

The guard lives in the ordinary framework unit suite, so it runs in the coding
loop and inside `test:framework:all` on Verify without a target of its own:

```bash
composer run test:framework:unit
```

A rule with a TypeScript half rides the frontend unit suite the same way, so it
too needs no target of its own:

```bash
composer run test:framework:frontend:unit
```

A failure lists every unbaselined hit as
`<RULE-ID> <path>:<line> — <what is wrong> (see <doc>)`, whichever of the two
suites produced it.

## Why guard tests and not PHPStan

- **No new dependency.** The rules are lexical, and `token_get_all()` already
  ships with PHP. PHPStan or a fixer would add a toolchain to install, pin, and
  keep green.
- **A precedent already exists.** `framework/tests/Unit/PageSubscriptionContractTest.php`
  guards a contract the same way.
- **It lands in both loops for free.** A PHPUnit test is already run by the
  coding loop and by the full run; a separate tool would need a new composer
  target and a place in every pipeline.

A rule that needs type resolution rather than tokens — propagating documented
`@throws` through call chains, for example — does not belong here. That one is
PHPStan's `missingCheckedExceptionInThrows`, and it is a leaf of its own.

## The baseline

Existing debt is recorded in `framework/tests/CodeStyle/baseline.txt`, one record
per rule and file:

```text
RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php 1 # HIL-508
```

- **The anchor is the file and a count, not a line number.** Any edit above a
  violation shifts its line; the count survives.
- **The ticket is mandatory.** A record names the leaf that will remove it, so
  the file reads as a list of owed work instead of a mute list. A record without
  a `HIL-nnn` fails the guard.
- **The baseline can only shrink.** More hits than recorded fails with the new
  lines; fewer hits asks you to lower the count; nothing left asks you to delete
  the record.

Regenerate it after paying debt off — the update mode rewrites the file and then
fails on purpose, so the diff is reviewed rather than committed blind:

```bash
docker compose -f framework/docker/docker-compose.yml run --rm \
  --user "$(id -u):$(id -g)" -e CODESTYLE_BASELINE_UPDATE=1 \
  hilos-cli-test php vendor/bin/phpunit -c framework/tests/phpunit.xml \
  --testsuite unit --filter CodeStyleGuardTest
```

Pass `--user` as shown: the test container otherwise runs as root and leaves a
root-owned `baseline.txt` behind. A record the update mode cannot attribute is
written with `TODO-TICKET`, which is not a valid ticket — the next run fails
until a person names the owing leaf.

## Adding a rule

1. Write or extend the canonical rule in `docs/agents/*` first, and add
   *"Checked automatically: `<RULE-ID>`"* next to it.
2. Implement `Hilos\Tests\CodeStyle\CodeStyleRule` under
   `framework/tests/CodeStyle/Rule/`. `check()` receives the file path **relative
   to the scanned root** and the `token_get_all()` output, and yields one
   `Violation` per occurrence, not per line.
3. Read tokens, not raw text. Both current rules depend on it: a docblock rule
   that reads the file as a string would fire on line comments, and a call rule
   would fire on the same name quoted inside a string literal.
4. Seed `framework/tests/CodeStyle/Fixtures/` with both a case that must be
   caught and a look-alike that must not, and pin the exact report in
   `RuleFixtureTest`. The guard leaves that directory out of its scan — by exact
   path, never by directory name — so this test is the only thing proving the
   rule still fires.
5. Register the rule in `CodeStyleGuardTest`, regenerate the baseline, and give
   every new record an owing leaf.

A rule that judges production code only is listed in `BACKEND_ONLY_RULES` in the
same test. It cannot decide that for itself: `check()` receives the path relative
to the scanned root, so `framework/tests/Unit/X.php` arrives as `Unit/X.php` and
is indistinguishable from a backend file. The root is known to the guard.

### A rule with a second half in another language

A rule whose subject crosses the PHP↔TypeScript boundary is written twice, once
per side, under **one** rule id and one owning document — `WIRE-KEY-CASE` is the
first. Each half judges by the convention of its own side, because what declares
a field key is written down differently there, and the two halves print the same
line so a report reads the same whichever produced it.

The TypeScript half lives in `framework/frontend/codestyle/`, a vitest project of
its own like `framework/frontend/scripts/` and, like it, not an npm workspace: it
ships no package and is not type-checked by `npm run check`. Register it by
adding its directory to `projects` in `framework/frontend/vitest.config.ts`; from
then on it runs inside `test:framework:frontend:unit` with no target of its own.
Read sources through the TypeScript compiler API (`ts.createSourceFile`) for the
same reason the PHP half reads tokens — a name quoted inside a string or written
in a comment is not a declaration.

The one entry in the table above stays one entry. Two rows would let the halves
drift apart in the register that exists to show they have not.

### A rule that reads markdown

The steps above describe a rule over PHP tokens. A rule that judges the agent
docs has a second home, and shares nothing with the first but the failure-line
format:

1. Put it in `framework/tests/CodeStyle/Markdown/`, next to `MarkdownSources`,
   which lists the scanned files, tells a router from a document, and resolves
   the text of a reference into repository paths.
2. Do not implement `CodeStyleRule`. That interface takes the `token_get_all()`
   output of one PHP file, which markdown has none of. Do not invent a shared
   interface for the markdown rules either: `DOC-ROUTE` judges the tree as a
   whole and `DOC-LINK` a file at a time, and one signature over both would be a
   likeness rather than a contract.
3. Yield finished report lines, not `Violation` objects. A `Violation` exists to
   carry a baseline key, and these rules have no baseline: both halves land
   green, and a debt list here would read as permission to leave the next rule
   file unrouted.
4. Seed `framework/tests/CodeStyle/Fixtures/AgentDocs/` with a toy tree — its own
   catalog, its own wrapper — and pin the exact report in `AgentDocFixtureTest`.
   Seed the look-alikes that must stay silent as carefully as the hits. Those
   fixtures need no path exclusion, unlike the PHP ones: the live scan covers
   `docs/**`, `agents.md`, `CLAUDE.md`, `skills/*` and `demo/*`, and
   `framework/tests` is not among them.
5. Register the rule in `AgentDocGuardTest`, one test method per rule, so two
   unrelated failures do not arrive as one.
