# Files Registry: Publishing, Storing, Binding And Sweeping A File

Read this before publishing a received upload, keeping a published file in a
project, linking a project record to one, changing where the files are kept, or
changing how files nobody linked are cleaned up. The machinery is
`framework/backend/Files/` (the library under `Library/`, the storage under
`Storage/`); the rows are the framework table `hilos_file`, the collection
`Hilos::$db->files`.

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

That is the whole contract. Until the call arrives, the janitor may take the
file; after it, the janitor never touches it again. There is no way back to
unbound yet.

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
`AbstractFilesLibraryAgent` / `AbstractFilesLibraryAgentDaemon`: the registry has
no project half. The project also copies the migration stub
`create_hilos_file.sql`, folds `FilesSettingsCatalog::getCatalog()` into its
settings catalog, and registers the files directory in its FS context:

```php
$this->registerDirectory(FsContext::FILES, $path);
```

`FsContext::FILES` is a name the framework reserves, as it reserves `tmp`. The
start refuses the feature without the library pair (and the pair without the
feature), without the settings library or the catalog fragment, and — once the
FS context is configured — without the files directory. The chat demo
registers its published-attachments directory under this name too, so moving
attachments onto the registry moves no file.

## Publishing

A row is born from a complete upload ([uploads.md](uploads.md)). The project
asks the door, from any process with a signal router:

```php
Hilos::$files->publishUploads($acceptKey, 'gallery', ['u1', 'u2'], FileVisibility::OWNER, 'gallery_published');
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
  for something else`; uploaded by a guest — `Sign in to keep this file`. The
  target is in the request so that a file accepted under one target's soft
  policy is not published under another's strict one.
- When all pass, each upload row goes and its temporary file stays, handed
  over; its connection gets the usual `gone` state frame. One frame carries the
  files to the library: temporary file, name, type — the one read from the
  content where the target sniffs, the declared one otherwise — declared size,
  owner and fingerprint.
- **The library** keeps each file under a random stored name (32 hex characters
  from the secure random axis, and an extension by type) and writes its row,
  unbound, with the visibility the request named. All kept →
  `{fileIds, error: null}`, one id per upload in the order of the request.
- **All or nothing.** A failure on one file — the storage did not keep it, the
  row was not written, a field was refused — removes the rows this request
  wrote together with their files, deletes the temporary files still waiting,
  logs the error and answers `Cannot keep the file` with no id. Not left to the
  janitor: the asker will link nothing, and an unbound row would hold its place
  in the storage limit for a day. Only those three failures are caught.

The project reads the answer: `error` not null → tell the person; otherwise
write its own links, then `markBound($fileIds)`. A file the project never binds
goes to the janitor. Publication does not know about moderation: the chat will
call it on approval (HIL-144), a gallery at once.

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
takes them out.

The framework ships `LocalFilesStorage`: the file of the stored name in the
files directory, asked for on every call, because the facade creates the door
before the FS context is configured. It renames the temporary file, and when the
rename cannot cross to the volume the files directory sits on, it copies and
deletes. A project keeps its files elsewhere by overriding the facade's
`createFilesStorage()`.

## The Content Fingerprint

`content_hash` is the sha256 of the file's bytes, lowercase hex (`ContentHash`).
The uploads agent counts it while the chunks arrive and writes it onto the
complete upload; the library copies it onto the row. The duplicate check
([uploads.md](uploads.md)) looks it up among the same owner's rows through the
index `idx_file_owner_hash (owner_user_id, content_hash)`.

A row never shares its file with another: one copy for several links needs
reference counting, and without it the janitor or an unbind would delete the
file from under the second link.

## Binding

`markBound()` sends the frame `hilos_file_bind` (`{fileIds: list<int>}`) to the
library and answers nothing: the project's link is already written, so there is
nothing to wait for. An empty list sends nothing. A project that did not declare
`FILES` is refused at the door (`FeatureNotDeclaredException`) — the frame would
reach nobody and the caller would believe its files kept. In the library an id
the registry does not hold is a warning, and a row already bound is not written.

## The Janitor

Every 15 minutes the library takes up to 100 unbound rows older than the
setting `files.unbound_ttl_hours` (default 24; zero or less switches the janitor
off, read on every pass), oldest first, and for each one, in this order:

1. removes the **row**. A foreign-key refusal means a project row links the file
   and only its bind frame was lost: the row is marked bound instead, the file
   stays, and a warning says so;
2. removes the **file**, through the storage. An absent file is not an error. A
   file that will not go is logged as an orphan, and the row is not brought
   back: a spare file on disk is cheaper than a row pointing at nothing.

A full batch that did something makes the next tick continue without waiting
for the schedule. Only these two refusals are caught; anything else — the
database, the setting — leaves the tick, as in
[code-style/wiring-refusals.md](../code-style/wiring-refusals.md).

The janitor never walks the storage. A file without a row is not its own — in
the chat, the files directory also holds attachments published before the
registry existed.

## What Is Not Here

- Serving a file and checking its `visibility` (`FileVisibility`) — HIL-138,
  which adds reading to the storage seam.
- Unbinding, and moving chat attachments onto the registry — HIL-144.
- One copy shared by several links, and a quota per person.
- Placing the uploads agent and the library on one node: the temporary
  directory is local, so on two nodes the library does not find the file and
  answers `Cannot keep the file`.
- Backing up the files themselves: a backup carries the database only.
