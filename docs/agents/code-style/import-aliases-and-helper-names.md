# Import Aliases And Helper Names

Read this before adding or changing PHP imports, aliases, or private helper
method names in backend code.

## Rule

Import aliases and helper names must preserve domain meaning.

An alias is allowed only when it resolves a name conflict or adds missing
context. The alias must clarify the imported symbol, not shorten it.

Helper method names must be specific enough to understand without reading the
local `use` section or reconstructing surrounding context.

## Import aliases

```php
// Forbidden: ObjectPiece hides the actual domain object.
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;

// Allowed: GroupItem adds context to an otherwise generic Item class name.
use Demo\Chat\Database\View\Group\Item as GroupItem;
```

If there is no conflict and the short class name is already clear, import the
class without an alias.

```php
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece;
```

## Helper names

```php
// Forbidden: Piece is too generic and loses the domain meaning.
private function rowFromPiece(ModeratorPromptPiece $moderatorPromptPiece): ModeratorPromptPieceTableRow;

// Good: the method name carries the same domain term as the source object.
private function rowFromModeratorPromptPiece(ModeratorPromptPiece $moderatorPromptPiece): ModeratorPromptPieceTableRow;
```

Avoid short helper names whose meaning depends on imports, local variables, or
the current file name. Prefer a longer name when it lets the caller understand
the code at the call site.

## Exceptions

Aliases are acceptable when:

- Two imported symbols have the same short name.
- The original short name is generic, such as `Item`, and the alias adds
  domain context.
- The alias expands context for generated or framework code whose class names
  intentionally repeat across layers.

Do not add an alias just to make a line shorter.
