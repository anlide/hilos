# Files Registry: Storing, Binding And Sweeping A Published File

Read this before keeping a published file in a project, linking a project record
to one, or changing how files nobody linked are cleaned up. The machinery is
`framework/backend/Files/` (the library under `Library/`); the rows are the
framework table `hilos_file`, the collection `Hilos::$db->files`.

## Core Rule

A published file has one row in `hilos_file` and one file of the same name
(`stored_name`) in the project's **files directory**. The table has one writer,
the files library (`AbstractFilesLibraryAgent`, `hilos_files_library`), which
claims it outright: it creates rows, marks them bound, and removes them. Nothing
else writes the table.

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
2. removes the **file**. An absent file is not an error. A file that will not go
   is logged as an orphan, and the row is not brought back: a spare file on disk
   is cheaper than a row pointing at nothing.

A full batch that did something makes the next tick continue without waiting
for the schedule. Only these two refusals are caught; anything else — the
database, the setting — leaves the tick, as in
[code-style/wiring-refusals.md](../code-style/wiring-refusals.md).

The janitor never walks the directory. A file without a row is not its own —
in the chat, the directory also holds attachments published before the
registry existed.

## What Is Not Here

- Creating a row from another process, and the storage driver that will wrap
  the files directory — HIL-136. Today `Hilos::$db->files->actions->create()`
  runs in the library's own process only.
- Serving a file and checking its `visibility` (`FileVisibility`) — HIL-138.
- Unbinding, and moving chat attachments onto the registry — HIL-144.
- Backing up the files themselves: a backup carries the database only.
