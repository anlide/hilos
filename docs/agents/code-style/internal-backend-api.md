# Internal Backend API

Use this rule before changing backend contracts between pages, table actions,
DB collection actions, object/view layers, agents, and runtime code.

## Rule

Do not use unstructured arrays as normal internal backend API.

Inside backend code, business data should move through one of these contracts:

- Typed method parameters for small, stable operations.
- DTOs or value objects for named payloads with several fields.
- Typed collections for lists of same-kind business objects.
- Existing DbCollection/Object/View collection APIs when the data already lives
  in a collection layer.

Arrays are allowed at system boundaries:

- Frontend action payloads and WebSocket transport DTOs.
- JSON, serialization, deserialization, `toArray()`, and `fromArray()`.
- Raw DB rows and low-level migration/seed payloads.
- External API payloads.
- Infrastructure code whose contract is intentionally array-shaped.
- Project topology and routing config constants (`PAGES`, `AGENTS`, `GROUPS`,
  `AGENT_SIGNALS`, page `SIGNALS`, `ACTIONS`, `BROWSER`, and similar declarative
  config arrays). These are framework-consumed registries, not internal backend API.

If an array remains in internal backend API, the reason must be obvious from
the surrounding boundary or documented in PHPDoc.

## Magic-string keys in structured arrays

Moved. The fixed keys of a structured array are one case of a repeated literal,
and the whole rule — numbers and strings alike — now lives in
[magic-values.md](magic-values.md). The boundary exceptions listed above
(`toArray()` / `fromArray()`, raw DB rows, external payloads, declarative
topology config) apply there unchanged.

## DB actions

Do not introduce `create([...])` or similar ad-hoc array parameters for DB
actions when the operation can be expressed as a strict method.

```php
// Wrong: caller has to know an unstructured field map.
$dbBot = Hilos::$db->bots->actions->create([
    ObjectBot::name => $name,
    ObjectBot::personality => $personality,
    ObjectBot::active => $active,
]);

// Good: first option for a small stable contract.
$dbBot = Hilos::$db->bots->actions->create($name, $personality, $active);

// Good: use a DTO/value object when the contract becomes large or reused.
$dbBot = Hilos::$db->bots->actions->create(
    new BotCreateData(
        name: $name,
        personality: $personality,
        active: $active,
    ),
);
```

Keep the same shape for moderator prompt pieces:

```php
// Wrong.
$dbPiece = Hilos::$db->moderatorPromptPieces->actions->create([
    ObjectPiece::section => $section,
    ObjectPiece::promptPiece => $promptPiece,
]);

// Good.
$dbPiece = Hilos::$db->moderatorPromptPieces->actions->create($section, $promptPiece);
```

Settings already model the preferred shape: the action names the operation and
takes typed parameters instead of a generic field map.

```php
$dbSetting = Hilos::$db->settings->actions->add($key, $value, $catalog);
```

## Table and page code

Frontend payloads can start as raw action data, but page code should parse them
into an action DTO before using them. After parsing, do not rebuild the same
business data as an intermediate array just to call the next backend layer.

```php
// Wrong: DTO fields are copied into an ad-hoc backend array.
$data = [
    ObjectPiece::section => $dto->section,
    ObjectPiece::promptPiece => $dto->promptPiece,
];
$dbPiece = Hilos::$db->moderatorPromptPieces->actions->create($data);

// Good: pass a typed contract to the next layer.
$dbPiece = Hilos::$db->moderatorPromptPieces->actions->create(
    $dto->section,
    $dto->promptPiece,
);
```

## Lists

Do not return `array` for lists of same-kind business objects when a typed
collection can express the result.

```php
// Wrong for internal business API.
/** @return array<int, Bot> */
public function activeBots(): array;

// Good.
public function activeBots(): Bots;
```

Use `list<T>` only for boundary payloads or small infrastructure helpers where
there is no meaningful collection type.

### Object and View collection methods

In `Database/Object/Collection/` and `Database/View/Collection/` under
`framework/backend` and `demo/*/backend`, use a typed collection for every
list of objects passed into or returned by a named method. This includes
private, protected, static and abstract methods, and methods in traits and
interfaces. The element may be any class, interface, enum, DTO or value object,
not just the collection's own item. Reuse an existing collection when it
expresses the result; preserve ordering through that typed result.

```php
use Hilos\Database\Object\Collection\Countries as ObjectCountries;

// Wrong: a DTO list is an object list too.
/** @return list<CountryLocaleChoice> Ordered locale choices */
public function localeChoices(): array;

// Good: use the existing typed collection for a set of country rows.
public function countries(): ObjectCountries;
```

The only array conversion exceptions in this layer are the `@param` of
`fromArray()` and the `@return` of `toArray()`. Match the whole method name,
case-insensitively. The opposite directions are still judged, as are names
such as `fromArrayLike()`. `ObjectCollection::fromArray()` and
`ObjectCollection::toArray()` are existing examples of the legal directions.
Small size, infrastructure work, a promised order or a comment do not grant
an exception here. The small-infrastructure-helper allowance above applies
outside this layer.

Checked automatically: `COLLECTION-OBJECT-LIST`, see
[automated-checks.md](automated-checks.md). The lexical check reads method
`@param` and `@return` tags, one hit per violating tag, at the tag's line;
`@param` must name a real method parameter. It recognizes `list<X>`,
`non-empty-list<X>`, `array<X>`, `array<K, X>`, `non-empty-array<K, X>` and
`X[]` with a direct object element, including nullable, union and intersection
wrappers and object/scalar unions inside the element. The key `K` is not an
element. Named non-builtin types are objects regardless of capitalization;
generic objects, `object`, `self`, `static`, `$this` and locally declared
object-bounded templates count too. Scalar types, `mixed`, `array-key`,
`class-string<X>`, `key-of<X>`, `value-of<X>` and unconstrained templates do not
become objects because a class name appears in their text. A typed
`Collection<X>` is not a raw container.

The check does not load production classes or infer types from method bodies.
Bare native `array`, structured array shapes, inter-file PHPDoc aliases,
class-level `@method` tags and other layers remain outside it. A green guard
is not proof that all internal arrays satisfy this document.

HIL-1531 freezes the existing violations once, without rewriting production
collections. A later leaf that changes a collection file must pay off this
rule's debt in that file and lower or remove its baseline record. The baseline
counts tags per rule and file: growth and new files fail, but replacing a hit
with another at the same count is invisible to it. Do not substitute new debt
for old debt; review enforces that ban and the payoff on touching a file.
