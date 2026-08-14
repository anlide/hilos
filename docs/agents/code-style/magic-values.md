# Magic Values

Read this before writing a bare number or a bare string into production code, and
when a review says "that literal is magic". The rule covers the production roots —
`framework/backend`, `demo/*/backend` and `scripts`. Test roots are out of scope — see
[Tests are not in scope](#tests-are-not-in-scope).

## The three tests

A literal is magic if **any one** of these holds. If none holds, it is data and
stays a literal.

### 1. REPEAT — the value is read or written in more than one place

One place means one place: the same file, and also the same value declared
independently by two classes. The cure is a constant that owns the value; for the
fixed keys of a structured array a value object is better still.

```php
// Wrong: the same budget, written twice, free to drift apart.
$deadline = microtime(true) * 1000 + 2000.0;
...
if (microtime(true) * 1000 > $started + 2000.0) {

// Good: one declaration, both readers point at it.
private const float MAX_WAIT_MS = 2000.0;
```

Checked automatically: `MAGIC-REPEAT` — numbers only, one file at a time. See
[the honest boundary](#what-the-machine-checks) below.

### 2. UNIT — a number in arithmetic or a comparison whose unit is invisible

`* 1000`, `> 2000.0`, `hex(8)`: from the place of use the reader cannot tell
milliseconds from microseconds, or bytes from characters. The cure is a constant
whose **name carries the unit**, not the value.

```php
// Wrong: which of the two conversions is this?
$currentTimeMs = microtime(true) * 1000;

// Good: the expression says what it converts.
$currentTimeMs = microtime(true) * TimeConstants::MS_PER_SECOND;
```

Name the unit, never the digits: `MS_PER_SECOND`, `MAX_WAIT_MS`,
`CORRELATION_ID_BYTES` — not `THOUSAND`, not `TIMEOUT_2000`. A constant named
after its value has to be renamed the day the value changes, which is the one day
you are least willing to touch it.

### 3. FOREIGN VOCABULARY — a string out of a closed set that already has an owner

`'test'` is an `AppEnv` case; `'GET'` is an `HttpConstants` name; `500` is
`HttpConstants::HTTP_INTERNAL_ERROR`. The cure is to **use the existing owner**,
not to declare a local copy of it.

```php
// Wrong: a second home for a value that already has one.
private const string METHOD_GET = 'GET';

// Good.
HttpConstants::METHOD_GET
```

If the set is closed but has no owner yet, the file that reads it twice becomes
the owner — that is test 1, not a licence to start a new `Constants` class for
one value.

## Data — the literal stays a literal

- **A unique descriptive value read in exactly one place**: a route path
  `'/status'`, the text of a message, a fragment of SQL, a date format. Naming it
  adds a hop for the reader and buys nothing: the name would only repeat the
  value in words.
- **`0`, `1`, `2`, `-1` and the empty string in a structural role**: an index, an
  increment, a half, "not found", "nothing yet". They carry no unit and mean the
  same everywhere.
- **A default sitting next to the name of the setting it belongs to**: the
  entries of a data catalog, below.

The pair that draws the line, both on one line of code:

```php
$router->add('GET', '/status', $handler);
//            ^ closed set, owned by HttpConstants — test 3 fires
//                   ^ unique description read once — data, stays a literal
```

### Data catalogs

A catalog of defaults — the table a `CatalogProviderInterface` implementation
hands back, mapping a setting name to the value it takes when the environment
says nothing — is data all the way through, and none of the three tests fires on
it. The default already stands next to the name that describes it, so there is
nothing left for a constant to say. And when two settings hold the same number,
that is a coincidence between two decisions taken apart from each other rather
than one value written twice: `MAIL_TIMEOUT_MS` and `SMS_TIMEOUT_MS` are two
timeouts, and four retention windows that all read `90` are four windows.

Naming such a pair once would do the damage the section below describes, one
setting away from the reader — it would rule that changing the mail timeout also
changes the SMS one, which nobody agreed to.

The checker agrees, by a rule wider than catalogs: it never counts a number
inside the value of a keyed array entry, however deep in that value the number
sits. Every entry of a catalog hangs on the setting's name, so the whole table
is out of its reach, and
`framework/tests/CodeStyle/Fixtures/Good/EnvCatalogLookAlike.php` pins that it
stays so.

## The same value is not one quantity

Two things that happen to be equal today are still two things. Give them two
constants, even with the same value on the right-hand side:

```php
// Wrong: one constant now claims the two budgets are linked.
private const float TIMEOUT_SEC = 90.0;

// Good: equal today, free to diverge tomorrow.
private const float DEFAULT_CHAT_BOT_TIMEOUT_SEC = 90.0;
private const float DEFAULT_CHAT_MODERATION_TIMEOUT_SEC = 90.0;
```

Collapsing them saves one line and creates a rule nobody agreed to — that
changing the bot's budget also changes moderation's. The checker agrees: numbers
inside a `const` declaration are not counted, so two constants of equal value are
silent.

The mirror case is just as real: one quantity written by two classes is **one**
repeat, and the cure is a single owner both of them read. `POLL_INTERVAL_US`,
declared privately by six framework CLI commands, is that case: those six now
read it from `CommandConstants`. The four `demo/chat` commands keep their own
copy on purpose — HIL-546 settled that a demo shows a command standing on its
own, so the value has one owner in the framework and a deliberate local
declaration in the demos. Nothing here is checked by machine: the rule reads one
file at a time and cannot see either half of this.

## Categories worked out

| Category | Example | Cure |
|---|---|---|
| Route path | `'/status'` in one route declaration | none — data |
| Catalog default | `'MAIL_TIMEOUT_MS' => entry(10000)` in a data catalog | none — data |
| HTTP method name | `'GET'`, `'POST'` | `HttpConstants` |
| HTTP status | `500` handed to an error responder | `HttpConstants::HTTP_INTERNAL_ERROR` |
| Time units | `microtime(true) * 1000` | `TimeConstants::MS_PER_SECOND` |
| Wait budget | `> 2000.0` twice in a command | class constant `MAX_WAIT_MS` |
| Separator | `'\|'` joining the parts of a key | class constant naming the key it builds |
| Bit position | `>> 7` for the high bit, twice | class constant `HIGH_BIT_SHIFT` |
| Set shared by a class family | the `pcntl_*` pair every manager needs | one constant on the base class |
| Fixed keys of a structured array | `['token' => …, 'frame' => …]` | named constants, or a value object |

## Magic-string keys in structured arrays

The last row of the table is the oldest form of this rule and a special case of
test 1. It used to live in [internal-backend-api.md](internal-backend-api.md).

Do not leave magic strings as the fixed keys of an internal structured array,
even when the array is private to a class and its shape is already documented in
PHPDoc. A documented `array{...}` shape removes the type risk, but the repeated
string literals stay a maintenance and typo risk.

When a fixed-key array is read by the same string literals in more than one
place, remove the magic strings, in order of preference:

- At minimum, replace the string-literal keys with named constants, so each key
  is declared once and cannot drift between the sites that read it.
- Preferably, model the value as a value object with typed, readonly properties,
  drop the array shape, and read the data through property names instead of keys.

Keep a documented `array{...}` shape only when no value object expresses it more
clearly; do not keep the bare string literals.

```php
// Wrong: fixed keys read as string literals in several methods.
$entries[] = ['token' => $token, 'frame' => $frame];
$top = $entries[array_key_last($entries)]['frame'];

// Minimum: named constants for the keys.
$entries[] = [self::KEY_TOKEN => $token, self::KEY_FRAME => $frame];

// Preferred: a value object; keys become typed properties.
$entries[] = new FrameStackEntry($token, $frame);
$top = $entries[array_key_last($entries)]->frame;
```

This does not apply to boundary arrays — JSON, `toArray()` / `fromArray()`, raw
DB rows, and the other system boundaries listed in
[internal-backend-api.md](internal-backend-api.md) — where string keys are part
of the wire or storage shape.

The boundary exception covers backend code that reads the boundary in one place.
It does not carry to the frontend: one row-payload key there is read by the core
resolver and named again by the Vue, React, and Angular views, so the literal is
a copy per package, not a boundary. See
[wire-key-ownership.md](wire-key-ownership.md).

## What the machine checks

Checked automatically: `MAGIC-REPEAT` (see
[automated-checks.md](automated-checks.md)). The check is **narrower than this
document, on purpose**, and a green run does not mean the rule is satisfied:

- **numbers only.** A repeated string in backend code is far more often a wire
  key or a piece of SQL than a magic value, and tokens cannot tell the two apart.
  Tests 1 and 3 on strings stay a matter for review.
- **one file at a time.** A rule reads a single file, so the same value declared
  by two classes — the `POLL_INTERVAL_US` case above — is invisible to it.
- **production roots only**, and never inside a `const` declaration, which is the
  cure rather than the disease.
- **never inside the value of a keyed array entry**, however deep in that value
  the number sits. The key names the entry, so a catalog of defaults where two
  settings happen to share a budget is two quantities and not a repeat — the
  section above, enforced. The price is paid knowingly: a genuine repeat written
  inside array values is missed, and a list has no key, so `[3000, 3000]` is
  still a hit.
- `0`, `1`, `2` and their float spellings are allowed however often they appear.
  `-1` is not in the allow list because it does not need to be: the minus is a
  token of its own, so `-1` reaches the checker as `1`. That split cuts both
  ways, and this is the direction that costs you: `max(-1500, min(1500, $n))` is
  one bound written twice as far as the tokens go, and the checker reports it as
  a repeat of `1500`.

There is no suppression comment, by the same decision that governs the other
checked rules. A hit you disagree with is an argument about this document: change
the rule here, and the checker follows.

## Tests are not in scope

A repeated number in an assertion is example data. Naming it hides from the
reader exactly the value the test is about:

```php
// Right in a test, wrong in production.
$this->assertSame(90.0, $catalog['chat_bot_timeout_sec']['default_value']);
```

`framework/tests` and `demo/*/tests` are therefore outside both the rule and the
checker.
