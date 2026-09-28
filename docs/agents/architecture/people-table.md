# Architecture: The People Table

Read this before touching the table that holds a person — `hilos_user`: adding a
column to it in a project, renaming a person, folding one account into another,
or writing a framework table that points at a person. A person is not an admin
feature: sign-in, sessions, notifications, the data export and the legal texts
all read this table, so its rules live here and not in
[admin-features.md](admin-features.md), which keeps the admin boundary and the
account card and points here.

This page is written ahead of its code — it is the target structure the leaves
of epic HIL-1133 land on. Every sentence about a mechanism that does not exist
yet carries a marker naming the leaf that lands it, and that leaf clears the
marker in the same commit ([../rule-authoring.md](../rule-authoring.md), *A Rule
Written Ahead Of Its Code*).

## Core Rule

The person is the framework's table `hilos_user`, with the columns `id`, `name`,
`admin`, `block`, `last_activity`. Its Entity declares `Entity::SET_STANDALONE` with `_setRoot = true` — a person belongs to
nobody's set and is the root other tables hang their sets on — and the verdict
`FAKE_NAME` on `name`. The collection key `users` is mounted by
`HilosDbContext`. A project carries the migration stub
`create_hilos_user.sql` among its own migrations, like every framework table.
The three demos migrate their former `user` tables to this shape. The shared
columns and indexes match the stub: `name` is `VARCHAR(255)`, while the code
keeps the rename limit at 64 characters.

The owner's frame (2026-09-24, the HIL-1111 interview), in the owner's words
rendered in English: *it has to be framework-level, and a project inherits it
and extends it as needed.* The occasion: asking why a passkey carries no foreign
key onto its way in, the interview found the stub rule "framework stubs never FK
across the framework/project boundary" standing on the person table being the
project's.

## Extending It

A project that needs columns of its own on the person extends `hilos_user` the
way any framework table is extended — by subclassing the whole ORM chain and
mounting it under the framework's `users` key
([../orm/inheritance.md](../orm/inheritance.md)). Not a side table of
additions, not a copy of the table under a project name.

The first live example is the chat demo: its subclass keeps `merged_into` on the
person until the merge tombstone table takes the fact over (HIL-1199).

## Who Owns The Row

The row belongs to the framework's people library — the users library agent
(`AbstractUsersLibraryAgent`), which already owns the person's ways in, codes,
reservations and second factor. Its claim on `users` is declared on the base
class itself, not in a project subclass.
Ownership and the reader interest a claim raises:
[truth-source.md](truth-source.md).

## What The Framework Does For A Person

Each operation below is the framework's on the target structure, and each row
names the leaf that moves it there. Until that leaf lands, the operation stays
where it is today — a hook the project implements.

| What | Leaf |
|---|---|
| Creating a person, the name shown for one, "an administrator is not deleted" (`assertAdministratorMayDelete()`) | HIL-1194 |
| Renaming a person — the write, the journal row, the notification | (not in the code yet — HIL-1195) |
| Creating the first administrator, granting rights, blocking (`applyAccountBlock()`), the replacement check before a grant is taken away | (not in the code yet — HIL-1197) |
| The `ADMIN` gate (`BrowserContext::isAdmin()`), reading `block` (the column itself, wherever a guard stands), the circle of administrators (`AdminAudience`, behind `ADMIN_AUDIENCE`), the "me" the handshake answers with (`AbstractAgent::handshakeIdentity()`) | HIL-1198 |
| The tombstone of a merged account and "is this account already folded" | (not in the code yet — HIL-1199) |
| Erasing a person — the framework deletes the person's row last, after the project's rows ([account-deletion.md](account-deletion.md)) | (not in the code yet — HIL-1200) |
| The people table and the merge-candidates table in the admin section | (not in the code yet — HIL-1201) |
| Foreign keys onto the person from every framework table that points at one | (not in the code yet — HIL-1202) |
| `name` and `lastActivity` on the frontend `User` entity | (not in the code yet — HIL-1193) |

What stays the project's: hooks over its own columns and its own rows — the chat
messages a merge re-attributes, the project's own tables an erasure clears. The
people library stays abstract by convention alone: the project registers its own
subclass, as it does every agent. `afterUserCreated()` stays the project's hook,
because what it writes lands in the project's own tables — the chat's
registration event in its room. How each of the other hooks is held is decided
by the leaf that moves the operation; this page does not name it.

## Renaming A Person

A rename is a framework operation of the people library (not in the code yet —
HIL-1195). One action writes `hilos_user.name` and one row of the framework's
rename journal: who was renamed, by whom — empty when the person renamed
themselves — the old name, the new name, when. A person renamed by somebody else
receives a framework notification. After the write runs the project's hook.

The handler of `hilos_user_admin_rename`
(`HilosSignalConstants::HILOS_USER_ADMIN_RENAME`) is the framework's; the
project's check BEFORE the rename — the chat demo's moderation of a name — stays
the project's. The journal is born with a foreign key onto `hilos_user`.

A project that needs something of its own on a rename row extends the journal by
[../orm/inheritance.md](../orm/inheritance.md); the chat demo's link from a
rename to the row of its feed is the first case (not in the code yet — HIL-1196).

The journal's name, its columns, the hook's form and the notification type are
HIL-1195's to introduce; this page does not name them.

## A Merged Account

The tombstone of an account folded into another is a framework table, one row
per folded account — who, into whom, when — and not a column on `hilos_user`
(not in the code yet — HIL-1199). The owner's decision D (2026-09-24), in the
owner's words rendered in English: *I do not want to spend a whole column on a
rare operation; let us plan a 1:1 table for it.*

With the table, "is this account already folded" and the tombstone itself are
framework code; the project moves only its own rows. The table's name and columns
are HIL-1199's to introduce.

## Foreign Keys Onto The Person

Every framework table that points at a person carries a foreign key onto
`hilos_user`, with a deliberate `RESTRICT` or `CASCADE` decided table by table
(not in the code yet — HIL-1202); the tables born under this epic — the rename
journal, the merge tombstone — are born with the key.

A soft reference is kept only where it has a reason of its own, the same two as
in [../orm/entity.md](../orm/entity.md): a delivery points softly at its
notification because the two are pruned independently, and the verifier circle
points softly at a way in because the pair is named before it has to exist.

The rule "framework stubs never FK across the framework/project boundary" was
revoked by the owner on 2026-09-24 — decision E, in the owner's words:
*let us revoke this rule.* It followed from the person table being the
project's, and that premise is gone. Its sentence in the existing migration
stubs is rewritten by the foreign-keys leaf (not in the code yet — HIL-1202); do
not copy it into a new stub.

## Anti-Patterns

- A `user` table of the project's own, copied per project. The person is
  `hilos_user`; a project adds columns by extending it.
- A side table of "extra columns" next to the person, or a copy of `hilos_user`
  under a project key. Extend the chain and mount it under `users`.
- A column on `hilos_user` for a rare fact — `merged_into`. The tombstone is a
  1:1 table.
- A soft reference onto the person justified by "framework and project do not
  FK across the boundary". The premise is revoked; declare the key and decide
  its `RESTRICT` / `CASCADE`.
- Renaming a person by editing the row from a project page or agent. The rename
  is the people library's operation, and the journal row and the notification
  come with it.
- Naming here what a neighbouring leaf introduces — the mount point, the
  journal's columns, the tombstone's name. This page states the rule; the leaf
  states the name.

## Related

- [account-deletion.md](account-deletion.md) — the erasure and its order.
- [admin-features.md](admin-features.md) — the admin boundary and the account
  card.
- [../orm/inheritance.md](../orm/inheritance.md) — how the chain is extended.
- [../orm/entity.md](../orm/entity.md) — the verdict and the set an Entity
  declares; the soft references that keep their reason.
- [truth-source.md](truth-source.md) — who owns the collection.
