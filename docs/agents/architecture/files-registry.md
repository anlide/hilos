# Files Registry: Publishing, Storing, Binding, Serving And Sweeping A File

Read this before publishing a received upload, keeping a published file in a
project, linking a project record to one, changing where the files are kept,
serving a file to a browser, or changing how files nobody linked are cleaned up.
The machinery is `framework/backend/Files/` (the library under `Library/`, the
storage under `Storage/`); the rows are the framework table `hilos_file`, the
collection `Hilos::$db->files`.

## Core Rule

A published file has one row in `hilos_file` and one file of the same name
(`stored_name`) in the registry's **storage** — today the project's files
directory. The table has one writer, the files library
(`AbstractFilesLibraryAgent`, `hilos_files_library`), which claims it outright:
it creates rows, marks them bound, and removes them, and it alone puts files
into the storage and takes them out. Nothing else writes either.

A row is born **unbound**. The project owes the registry one call once its own
link to the file is written — a chat attachment, a gallery entry:

```php
Hilos::$files->markBound([$fileId]);
```

Until the call arrives, the janitor may take the file; after it, the janitor
never touches it again. When the project later drops its links — deletes its
rows and commits — it owes one more call, and the files go at once
([Removing](#removing)):

```php
Hilos::$files->remove($fileIds);
```

There is no way back to unbound: a file the project no longer wants is removed,
not left to wait for the janitor.

## Activation

```php
protected const array FEATURES = [HilosFeature::FILES /* , ... */];

public const array AGENTS = [
    FilesLibraryAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => FilesLibraryAgent::class,
        AgentRegistryKey::DAEMON => FilesLibraryAgentDaemon::class,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
    ],
];
```

`FilesLibraryAgent` and its daemon are empty subclasses of
`AbstractFilesLibraryAgent` / `AbstractFilesLibraryAgentDaemon`: the registry
has no project half. The project also copies the migration stubs
`create_hilos_file.sql` and `create_hilos_file_variant.sql`, folds
`FilesSettingsCatalog::getCatalog()` into its settings catalog, and registers
the files directory in its FS context:

```php
$this->registerDirectory(FsContext::FILES, $path, DirectoryScope::CLUSTER);
```

`FsContext::FILES` is a name the framework reserves, as it reserves `tmp`. The
start refuses the feature without the library pair (and the pair without the
feature), without the settings library or the catalog fragment, and — once the
FS context is configured — without the files directory. The chat demo
registers its attachments directory under this name, which is why its earlier
attachments became registry rows without a file moving (HIL-144). The files
directory is the cluster's: every node and its nginx see it
([filesystem.md](filesystem.md)).

## Publishing

A row is born from a complete upload ([uploads.md](uploads.md)). The project
asks the door, from any process with a signal router:

```php
Hilos::$files->publishUploads($acceptKey, 'gallery', ['u1', 'u2'], 'gallery_published');
```

The request goes to the uploads agent (`hilos_upload_publish`), which hands the
files over to the library (`hilos_file_publish`), and the answer comes back
under the name the project gave — a `FilesPublishedSignalData` bound to the
connection (`acceptKey`), so a page receives it the way the chat page receives
a moderation verdict. Declare that name in the consuming agent's
`AGENT_SIGNALS`.

- **The door** refuses a project without `FILES` or without `UPLOADS`
  (`FeatureNotDeclaredException` — without the uploads agent the frame would go
  nowhere and the answer would never come) and a malformed request
  (`InvalidFormatException`: no id, an id twice, an id the wire does not allow,
  an empty target or answer name). No frame leaves.
- **The uploads agent** judges every named upload before it touches any, and
  the first refusal answers the whole request with nothing moved: the project
  keeps no files — `Files are not kept here`; no such upload, or it failed —
  `This file is gone; upload it again`; still arriving — `This file has not
  finished uploading`; declared for another target — `This file was uploaded
  for something else`. The target is in the request so that a file accepted
  under one target's soft policy is not published under another's strict one.
  The target's `visibility()` decides who may read the published row; the
  publication call cannot override it.
- **The owner is judged last**, against whoever is signed in on the
  connection of the request now — asked once per request, not taken from the
  declaration. Nobody is signed in — `Sign in to keep this file`. The upload
  was declared by another person than the one signed in — `This file is gone;
  upload it again`; the uploads agent logs a warning with the upload, the
  connection, who declared it and who is signed in. The upload was declared by
  a guest who has signed in since — it passes, and where the target switched on
  `DuplicateContentCheck` it is judged again for the person signed in: their
  bound file of the same content, or another complete upload of theirs on any
  connection, refuses the whole request with `This file is already uploaded`
  ([uploads.md](uploads.md), *Targets And Checks*). A failed read of the
  registry or the uploads there is not caught, as it is not on arrival.
- When all pass, each upload row goes and its temporary file stays, handed
  over; its connection gets the usual `gone` state frame. One frame carries the
  files to the library: temporary file, name, type — the one read from the
  content where the target sniffs, the declared one otherwise — declared size,
  owner and fingerprint. The owner is the person signed in at publication, not
  at the declaration. Between the upload row going and the registry row being
  written nobody counts the file in the storage limit — a known limit
  ([uploads.md](uploads.md), *Targets And Checks*).

  When that person is erased, `hilos_file.owner_user_id` becomes `NULL` through
  its `ON DELETE SET NULL` key (HIL-1202). After the commit the files library
  removes registry ids the project named from its deleted links; any other file
  row remains without an owner. An `owner`-visible row with no owner grants nobody
  access; a signed-in viewer is forbidden. The duplicate check searches by an
  actual owner's id, so it does not count an ownerless bound row as their file.
- **The library** keeps each file under a random stored name (32 hex characters
  from the secure random axis, and an extension by type) and writes its row,
  unbound, with the visibility the upload target declared. All kept →
  `{fileIds, error: null}`, one id per upload in the order of the request.
- **All or nothing.** A failure on one file — the storage did not keep it, the
  row was not written, a field was refused — removes the rows this request
  wrote together with their files, deletes the temporary files still waiting,
  logs the error and answers `Cannot keep the file` with no id. Not left to the
  janitor: the asker will link nothing, and an unbound row would hold its place
  in the storage limit for a day. Only those three failures are caught.

The project reads the answer: `error` not null → tell the person; otherwise
write its own links, then `markBound($fileIds)`. A file the project never binds
goes to the janitor. Publication does not know about moderation: the chat calls
it on approval, a gallery at once.

If the library dies in the middle of a request, the asker hears nothing and
waits out its own timeout; the handed-over temporary files stay on disk, as
after a killed uploads agent.

## Storage

The files are kept behind `FilesStorageInterface`, which lives on the door as
`Hilos::$files->storage` so that every process reaching the registry sees the
same one. It has two actions: `storeFromTmp($storedName, $tmpIndex)` — once it
returns the temporary file is gone and the storage holds the file under the
name — and `delete($storedName)`, for which a name nothing is kept under is not
an error. Only the library calls them: publication puts files in, the janitor
takes them out. Two reads serve a file (below): `size($storedName)`, null when
nothing is kept under the name, and `read($storedName)`, the whole file.

[Image variants](images.md) share that storage under their own random names.
The images agent reads the original and draws into tmp; the library alone
stores the copy and writes its row. The copy table belongs to FILES even in a
project without IMAGES, so cleanup and size accounting have one path.

The framework ships `LocalFilesStorage`: the file of the stored name in the
files directory, asked for on every call, because the facade creates the door
before the FS context is configured. It renames the temporary file, and when the
rename cannot cross to the volume the files directory sits on, it copies and
deletes. A project keeps its files elsewhere by overriding the facade's
`createFilesStorage()`. It is the only storage of this version; S3-compatible
storages and Azure Blob wait as a TODO at this seam —
[filesystem.md](filesystem.md), "Other Storages Later".

## The Content Fingerprint

`content_hash` is the sha256 of the file's bytes, lowercase hex (`ContentHash`).
The uploads agent counts it while the chunks arrive and writes it onto the
complete upload; the library copies it onto the row. The duplicate check
([uploads.md](uploads.md)) looks it up among the same owner's bound rows
through the index `idx_file_owner_hash (owner_user_id, content_hash)`; a row
nothing has bound yet does not count.

A row never shares its file with another: one copy for several links needs
reference counting, and without it the janitor or a removal would delete the
file from under the second link.

## Binding

`markBound()` sends the frame `hilos_file_bind` (`{fileIds: list<int>}`) to the
library and answers nothing: the project's link is already written, so there is
nothing to wait for. An empty list sends nothing. A project that did not declare
`FILES` is refused at the door (`FeatureNotDeclaredException`) — the frame would
reach nobody and the caller would believe its files kept. In the library an id
the registry does not hold is a warning, and a row already bound is not written.

## Removing

`remove()` is the door for a project that dropped its links: its rows are
deleted and committed — a history cleared, an account erased — and their files
should go now rather than wait out `files.unbound_ttl_hours`, holding their
place in the storage limit:

```php
$fileIds = Hilos::$db->eventAttachments->allFileIds();
Hilos::$db->events->actions->deleteAll();      // the project's links go first
Hilos::$files->remove($fileIds);
```

It sends the frame `hilos_file_remove` (`{fileIds: list<int>}`) to the library
and answers nothing. The door refuses as `markBound()` does: no `FILES`, a
`FeatureNotDeclaredException`; an empty list, nothing sent. Call it after the
commit, never inside the transaction that deletes the links: the library works
in another process, and a rolled-back link would point at a file already gone.

The library removes each row by the janitor's own steps — one function serves
both ([The Janitor](#the-janitor)): copy rows, the row, then the copy files and
the original through the storage. The differences are two. A bound row is
removed as readily as an unbound one: the call itself says the project's links
are gone. And a foreign-key refusal means a project row **still** links the
file — the project asked too early, or another of its rows points at it — so the
row stays as it was, neither marked nor retried, and a warning says so; its
copies' bytes go regardless, as the janitor's do. An id the registry does not
hold is a warning. The pass ends with one line, `Files removed: N, kept M`.

Who calls it today: the chat's history cleanup (every 30 minutes) and the
erasure of an account — the project's `applyAccountErasure()` names the registry
files its deleted rows pointed at in `AccountErasure::$fileIds`, and the session
holder calls `remove()` after the commit if the project declares `FILES`
([account-deletion.md](account-deletion.md)). Nothing deletes a registry file
past the library.

## The Janitor

Every 15 minutes the library takes up to 100 unbound rows older than the
setting `files.unbound_ttl_hours` (default 24; zero or less switches the janitor
off, read on every pass), oldest first, and for each one, in this order:

1. removes its **copy rows**, collecting their storage names first;
2. removes the **original row**. A foreign-key refusal means a project row links
   the file and only its bind frame was lost: the row is marked bound instead,
   the file stays, and a warning says so;
3. removes the **copy files and original**, through the storage. An absent file
   is not an error. A file that will not go is logged as an orphan, and the row
   is not brought back: a spare file on disk is cheaper than a row pointing at
   nothing.

Copy files are removed even when a project's foreign key keeps the original:
their rows already went, and leaving the bytes would leave orphans. The next
variant request draws them again. The copy's `ON DELETE CASCADE` foreign key is
a safeguard; deleting the copy rows first means the janitor never mistakes
its own copy for a project's link.

A full batch that did something makes the next tick continue without waiting
for the schedule. Only these two refusals are caught; anything else — the
database, the setting — leaves the tick, as in
[code-style/wiring-refusals.md](../code-style/wiring-refusals.md).

The janitor never walks the storage. A file without a row is not its own.

## Serving A File

A file is served at `GET /_hilos/file?id=N` (`HilosFiles::DOWNLOAD_PATH`; build
the address with `HilosFiles::downloadPath($fileId)`). The library declares the
address itself (`AGENT_HTTP_ROUTES`), so it exists on every project that
declares `HilosFeature::FILES` and nowhere else, and it is answered by the
library rather than by the master, which may read neither the row nor the
session — the mechanism is [agent-http-routes.md](agent-http-routes.md). The
browser's cookie rides along by itself, so the address is same-origin.

With IMAGES declared, `variant=<name>` asks for a named image copy, built with
`HilosFiles::downloadPath($fileId, $variant)`. The same access check applies;
a missing copy is drawn on its first request. The address, signature, renderer
and fallback policy are [images.md](images.md).

Who gets the file is the row's `visibility` (`FileAccess`), set from the upload
target at publication:

| visibility | served to | otherwise |
|---|---|---|
| `public` | anyone, no cookie needed | — |
| `authenticated` | a session with a user and an expiry still ahead | 401 |
| `owner` | that session, when its user is `owner_user_id` | 401 unsigned, 403 signed in |

A guest session is not signed in, and neither is an expired one whose row still
names its user — the handshake's rule; the download reads the session and never
downgrades it. Under impersonation the owner check sees the impersonated user.
A missing or malformed id, a row that is not there and a file the storage does
not hold are all 404. Every refusal is JSON with `Cache-Control: no-store`, so
signing in opens the file on the next request.

**The project widens it, never narrows it.** When the visibility refused, the
library asks its own `grantsRead(File $file, ?Session $session): bool`, false by
default; a project overrides it in its subclass of the library to let in a
group, a role, the members of a room — or a guest, since it gets the session
whole. Forgetting it leaves a file to its owner rather than opening it.

The response: `Content-Type` is the row's type; images are `inline` and
everything else — SVG included, which would run its script on the site's origin
— is `attachment`, named twice, an ASCII fallback beside
`filename*=UTF-8''…`; `X-Content-Type-Options: nosniff`; a year of cache,
`public` for a public file and `private` otherwise, because a file never changes
under its id.

**Two transports.** With `HILOS_FILES_XACCEL_LOCATION` set, the body is empty and
`X-Accel-Redirect` names the stored file under that internal location; nginx
sends the bytes, and `Range` with them. Empty — the dev stack with no web server
in front — the daemon sends the bytes in the reply itself, up to 4 MiB
(`FileDownloadResponse::DIRECT_MAX_BYTES`, which keeps the base64 frame the
master decodes small); a larger file is a 500 whose line names the env. The
daemon sends its own body only to a browser whose connection this node holds:
it never rides the peer link, whose 8 MiB queue two such downloads would
overflow and drop together with every other frame between the nodes. A browser
on another node gets a 500 at any size, and the line names its node and the
env. The nginx side, beside the location proxying the daemon:

```nginx
location = /_hilos/file { proxy_pass http://daemon; }
location ^~ /_files_internal/ { internal; alias /path/to/files/; }
```

## What Is Not Here

- `Range` and streaming when the daemon sends the bytes itself.
- One copy shared by several links, and a quota per person.
- Placing the uploads agent and the library on one node: the temporary
  directory is local, so on two nodes the library does not find the file and
  answers `Cannot keep the file` — HIL-1241.
- Backing up the files themselves: a backup carries the database only.
