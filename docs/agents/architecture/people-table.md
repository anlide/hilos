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

No project extends the person today. The chat demo's subclass, the one that kept
`merged_into` on it, left when the merge table took that fact over (HIL-1199); the
first project chain over a people table is the chat demo's on the rename journal,
`demo/chat/backend/Database/Entity/Item/UserRename.php` and its column `event_id`
(HIL-1196).

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
| Renaming a person — the write, the journal row, the notification (`renameUser()`, `afterUserRenamed()`) | HIL-1195 |
| Creating the first administrator (`ensureAdminUser()`), granting and removing rights (`applyAdminGrant()`), blocking (`applyAccountBlock()`), whether one person may take another over (`assertImpersonationAllowed()`) | HIL-1197 |
| The `ADMIN` gate (`BrowserContext::isAdmin()`), reading `block` (the column itself, wherever a guard stands), the circle of administrators (`AdminAudience`, behind `ADMIN_AUDIENCE`), the "me" the handshake answers with (`AbstractAgent::handshakeIdentity()`) | HIL-1198 |
| The tombstone of a merged account and "is this account already folded" (`assertMergeable()`, the merge table `hilos_user_merge`), the refusals to a folded account | HIL-1199 |
| Erasing a person — the framework deletes the person's rename journal and row after the project's rows, along with accounts folded into that person ([account-deletion.md](account-deletion.md)) | HIL-1200 |
| The people table and the merge-candidates table in the admin section | HIL-1201 |
| Foreign keys onto the person from every framework table that points at one | HIL-1202 |
| A person's published photo, keyed by the person and linked to the files registry | HIL-1205 |
| `name` and `lastActivity` on the frontend `User` entity | HIL-1193 |

What stays the project's: hooks over its own columns and its own rows — the chat
messages a merge re-attributes, the project's own tables an erasure clears. The
people library and the sessions library stay abstract by convention alone: the
project registers its own subclass of each, as it does every agent.
`afterUserCreated()` stays the project's hook, because what it writes lands in
the project's own tables — the chat's registration event in its room. How each
of the other hooks is held is decided by the leaf that moves the operation; this
page does not name it.

## A Person's Photo

The optional `hilos_user_photo` table holds the published photo of one person,
linked to its registry file by a restrictive foreign key. It is owned by the
people library and reached through `Hilos::$db->userPhotos[$userId]`, without a
photo column on `hilos_user`. Its details, checking and browser wire are in
[profile-photo.md](profile-photo.md).

## Renaming A Person

A rename is a framework operation of the people library
(`AbstractUsersLibraryAgent::renameUser()`, HIL-1195). One transaction writes
`hilos_user.name` and one row of the framework's rename journal
`hilos_user_rename`: whom (`user_id`), by whom (`renamed_by_user_id`), the old
name (`old_name`), the new name (`new_name`), when (`renamed_at`). A name the
person already carries writes nothing.

Who renamed is the owner's frame (2026-09-28, the HIL-1195 interview), in the
owner's words rendered in English: *put the id of the user who renamed into
`renamed_by_user_id`, or null if it was not a user; never mind the old rows.* So
the column holds the person who did the rename — the renamed person's own id
when they renamed themselves — and is empty only when the author is not a person
(the system, a console). The rows the demos carried over from their own journals
never recorded the author and are empty there. When an author's account is
erased, the database clears the reference and the row stays: it is the renamed
person's history.

After the commit, a person renamed by somebody else — an empty author included —
receives the framework notification `user.renamed`
(`UserNotificationType::RENAMED`), not a mandatory one; then the project's hook
`afterUserRenamed()` runs with the journal row. What the hook writes is news: its
failure is logged and does not undo the rename.

The handler of `hilos_user_admin_rename`
(`HilosSignalConstants::HILOS_USER_ADMIN_RENAME`) is the framework's, and so is
the forwarding from the person's card (`AbstractHilosUserPage`); the project's
check BEFORE the rename — the chat demo's moderation of a name — stays the
project's. The journal is born with its foreign keys onto `hilos_user`.

A project that needs something of its own on a rename row extends the journal by
[../orm/inheritance.md](../orm/inheritance.md); the chat demo's link from a
rename to the event of its feed is the first case: the column `event_id`, which
its `afterUserRenamed()` writes through the journal row's item actions — empty in
the framework and left for exactly this (HIL-1196).

## Taking A Person Over

Whether one person may take another over is
`AbstractSessionsLibraryAgent::assertImpersonationAllowed()`, the one judge of
both ways in — the person's card and `impersonate:start`. The asker must be an
administrator, and only then is the target looked up. Then the impersonation
settings (`ImpersonationSettings`, HIL-1170): impersonation must be allowed
(`auth.impersonation.allowed`), and a blocked person, a frozen person (by
`AccountStandingResolver`) or another administrator is taken over only while
its switch is on (`auth.impersonation.blocked`, `.frozen`, `.equal`). A refusal
speaks the words the card shows under its switched-off button
(`ImpersonationMessages`). The asker naming themselves is not "another
administrator": the library refuses that on its own, after.

These settings are judged at the start and nowhere later: a takeover under way
is not ended by a later switch, the way a sign-in method switched off signs
nobody out. What may be done inside is judged on every action instead
([page-access-control.md](page-access-control.md), *The takeover gate*).

## A Merged Account

The tombstone of an account folded into another is a framework table, one row
per folded account, and not a column on `hilos_user` (HIL-1199). The owner's
decision D (2026-09-24), in the owner's words rendered in English: *I do not
want to spend a whole column on a rare operation; let us plan a 1:1 table for
it.*

The table is `hilos_user_merge`, mounted as `userMerges`: which account was
folded (`user_id`, the key of the row), into which (`survivor_user_id`), when
(`merged_at`). Keyed by the folded account, so an account is folded at most once
and "is this account merged" is `Hilos::$db->userMerges[$userId] !== null`. Two
columns are null by design: `merged_at` on the rows carried over from the chat
demo's former column, which never recorded the moment, and `survivor_user_id`
only on older rows left when the survivor was erased before HIL-1200 began
taking folded accounts with it.

Its two keys onto `hilos_user` are chosen apart. The folded account is
`RESTRICT`: its row goes only with the account's own erasure, which removes it
among the framework's rows, and a forgotten merge row stops that erasure loudly.
The survivor is `SET NULL`: it lets the survivor's row go if it is deleted first,
without making a folded account an unmerged candidate. Erasure takes folded
accounts first (HIL-1200), so this key does not run on its normal path. The
HIL-1202 confirms this choice as the pattern for the other person keys.

The table is the sessions library's whole, and the whole operation is framework
code (`AbstractSessionsLibraryAgent::mergeAccounts()`): whether the two accounts
may be merged — both exist, neither is folded already (`assertMergeable()`) —
then the passwords, then one transaction: the ways in, the project's own rows
(`applyAccountMerge()`, the one seam a project answers), and the tombstone. The
tombstone writes the merge row FIRST and the loser's block flag second: the
merge-candidates table hears of the merge by the change of the person's row, and
reads "is this account merged" from the table at that moment. The flag is
written straight, not as a block: a merged loser is not a punished person.

A folded account is refused, both ways, the admin flag and the block
(`applyAdminGrant()`, `applyAccountBlock()`), an administrator's deletion after
the refusal to an administrator (`assertAdministratorMayDelete()`), and a place
in the administrators' circle (`AdminAudience`) — the last one asked by name
even though the merge blocked it, since the flag can be lifted past the library.
The table is read process-wide beside the people for that reason. The person's
data copy carries both sides of their merges in its `merges` section.

## Foreign Keys Onto The Person

Every framework row that belongs to a person points at `hilos_user` with
`ON DELETE RESTRICT`. Account erasure deletes that row in its own transaction
before the person row; any forgotten row refuses the deletion and rolls the
erasure back. The four columns of `hilos_session` also use RESTRICT: erasure
signs out sessions bound to or impersonating the person, clears second-factor
waits and lowers blocked-account cards before deleting the person. A forgotten
session reference must refuse erasure too (HIL-1202).

Where a person is only mentioned inside another row, the row survives and the
mention uses `ON DELETE SET NULL`: `hilos_file.owner_user_id`,
`hilos_user_rename.renamed_by_user_id` and
`hilos_user_merge.survivor_user_id`. No person key uses CASCADE; it would delete
rows past their owner's write path. The owner's answer on 2026-10-03 was:
*the erasure itself, and a RESTRICT key*. The rename journal and merge tombstone
were born with their keys; HIL-1202 added the rest across six demos.

A soft reference is kept only with its own reason. The
`hilos_account_deletion.user_id` row deliberately survives the person as the
trace of their request, and the export builder reads it to discard an in-flight
copy. A delivery points softly at its notification because the two are pruned
independently; the verifier circle points softly at a way in because the pair
is named before it has to exist. `hilos_change_log` is an unwritten placeholder
without an Entity or writer; HIL-351 replaces it with partitioned receipts in a
separate database, where MariaDB cannot install these keys ([change-log.md](change-log.md)).

The rule "framework stubs never FK across the framework/project boundary" was
revoked by the owner on 2026-09-24 — decision E, in the owner's words:
*let us revoke this rule.* It followed from the person table being the
project's, and that premise is gone. HIL-1202 removed its sentence from the
existing migration stubs; do not copy it into a new stub.

## Anti-Patterns

- A `user` table of the project's own, copied per project. The person is
  `hilos_user`; a project adds columns by extending it.
- A side table of "extra columns" next to the person, or a copy of `hilos_user`
  under a project key. Extend the chain and mount it under `users`.
- A column on `hilos_user` for a rare fact — `merged_into`. The tombstone is a
  1:1 table.
- A soft reference onto the person justified by "framework and project do not
  FK across the boundary". The premise is revoked; declare the key and decide
  its `RESTRICT` / `SET NULL`. CASCADE is not used.
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
