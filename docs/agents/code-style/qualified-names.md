# Qualified Names in Executable Code

Read this when writing a class name in PHP code — a `catch`, a `new`, a type in a
signature, a base class, a static access. For a class named inside a docblock read
[phpdoc.md](phpdoc.md), which says the same thing about the same names one layer up.

## Rules

1. A class is named in code by its short name, imported with `use` at the top of
   the file. Do not write a leading-backslash fully qualified name:
   `catch (Throwable $e)` with `use Throwable;` above, not `catch (\Throwable $e)`.
   This holds for a class of the global namespace exactly as it does for one of
   ours — `Throwable`, `Socket`, `Closure` and `ReflectionClass` are imported like
   anything else. A name partially qualified against the current namespace —
   `new Agent\BackupAgent()` inside `Hilos\Backup` — is the same mistake with less
   punctuation: it reads against whatever namespace the file happens to declare,
   so it stops pointing at anything the day either class moves.
   Checked automatically: `CODE-FQN`, see [automated-checks.md](automated-checks.md).
2. A short name written in code must resolve: it is imported, declared in this
   file, or declared by a neighbour of the same namespace. PHP resolves an
   unqualified class name against the **current namespace**, never against the
   global one, so dropping the backslash without adding the import does not leave
   the name pointing where it did — see the paragraph below on why this one is
   worth a machine check.
   Checked automatically: `CODE-FQN`, see [automated-checks.md](automated-checks.md).
3. When the short name is already taken — the file declares a class of that name,
   or another import owns it — alias the import rather than keeping the fully
   qualified spelling: `use Hilos\Hilos as HilosFacade;` in a project's own
   `Hilos.php`. Alias naming is owned by
   [import-aliases-and-helper-names.md](import-aliases-and-helper-names.md).
4. A backslash inside a string literal or inside a comment is not a class
   reference and this document says nothing about it. A class name carried as
   data — a payload field, a log line, a catalog entry — stays written out,
   because there the name IS the value.
5. A global function needs no import and no backslash. Write `assert($frame
   instanceof PeerVoteReplyDTO)`, not `\assert(...)`: an unqualified function name
   falls back to the global namespace on its own, which is the one place PHP's
   fallback makes the short spelling correct by itself.

## Why the second rule carries a checker

The two spellings of a class name fail differently, and that asymmetry is the
whole reason this document exists.

A missing class in most positions is loud: the call errors on the first
execution, in the same second, with the name in the message. But three positions
say nothing at all. `catch (Throwable $e)` where `Throwable` resolves to
`Current\Namespace\Throwable` catches nothing and reports nothing — the code
reads as if it handled the failure, and the failure walks past it. `Foo::class`
hands back a string whichever namespace it resolved in, so a wrong resolution
becomes a wrong signal name, a wrong catalog key or a wrong log line rather than
an error. A parameter or return type only fails when a value of the wrong class
actually arrives, which may be in another subsystem, on another day.

Those are exactly the positions the checker judges. The rest is left to the fatal
error, which is a better messenger than any rule.

## What this document does not own

- A class named inside a docblock — [phpdoc.md](phpdoc.md), rules 9 to 12.
- What an alias is called once one is needed —
  [import-aliases-and-helper-names.md](import-aliases-and-helper-names.md).
- Whether a `Reflection*` call should exist at all — [reflection.md](reflection.md).
  This document only says how its class is spelled once the call is justified.
