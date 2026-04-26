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

If an array remains in internal backend API, the reason must be obvious from
the surrounding boundary or documented in PHPDoc.

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
