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
DB rows, and the other system boundaries listed above — where string keys are
part of the wire or storage shape.

The boundary exception covers backend code that reads the boundary in one place.
It does not carry to the frontend: one row-payload key there is read by the core
resolver and named again by the Vue, React, and Angular views, so the literal is
a copy per package, not a boundary. See
[wire-key-ownership.md](wire-key-ownership.md).

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
