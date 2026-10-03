# The File System: Whose A Directory Is And Where It Lives In A Cluster

Read this before registering a directory in `$fs`, handing a file to an agent
that may live on another node, putting a file's name into a frame, a signal or
a database row, choosing where a new kind of file is kept, or setting up the
shared volume and the nginx of a multi-node installation. The machinery is
`framework/backend/Fs/` (the context under `Context/`); the facade global is
`Hilos::$fs`.

## Core Rule

Every `$fs` directory is either its node's or the cluster's, and it says which
when it is registered: a `DirectoryScope` — `CLUSTER` or `NODE` in
`framework/backend/Fs/DirectoryScope.php` — the way an agent says its
`AgentScope`. A directory whose file a process of another node opens is the
cluster's. Between processes travel names only — the logical name of the
directory and the name of the file — never a path.

## The Context

A project subclasses `FsContext` (`framework/backend/Fs/Context/FsContext.php`)
and in `configure()`, which `Hilos::init()` calls, registers its directories
with `registerDirectory($name, $path, $scope)` and the temporary one with
`setTmpPath($path, $scope)`. The owner has no default: a registration that
forgets it does not pass the call. The declaration is read back with
`getScope()` on a directory and on the temporary one, and the context lists
its directories by name with `getDirectories()`. Code reaches a directory as
`Hilos::$fs->files` or `Hilos::$fs->tmp` (the magic `__get`), a file by its
name through the directory's ArrayAccess
(`framework/backend/Fs/FsDirectory.php`), and a temporary file by the 32-hex
index that `create()` returns (`framework/backend/Fs/FsTmpDirectory.php`).

The framework reserves five names: `tmp` (`FsContext::TMP`), `files`
(`FsContext::FILES`, HIL-336), `data_export` (`FsContext::DATA_EXPORT`,
HIL-303), `legal_export` (`FsContext::LEGAL_EXPORT`, HIL-1234, the
administrators' exports of acceptance records —
[legal-documents.md](legal-documents.md)) and `analytics_journal`
(`FsContext::ANALYTICS_JOURNAL`, HIL-1154, a node directory whose one owner is
the node's journal agent — [analytics.md](analytics.md)). The start refuses
`UPLOADS` without tmp, `FILES` without `files`, `AUTH` without `data_export`, a
registered legal agent (`HilosAgentType::HILOS_LEGAL`) without `legal_export`
and `ANALYTICS` without `analytics_journal` (`refuseUploadsWithoutTmp()`,
`refuseFilesWithoutDirectory()`, `refuseDataExportWithoutDirectory()`,
`refuseLegalExportWithoutDirectory()` and
`refuseAnalyticsWithoutJournalDirectory()` in `framework/backend/Hilos.php`). A
sixth refusal, `refuseMisdeclaredDirectories()`, throws
`InvalidTopologyException` when `files`, `data_export` or `legal_export` is declared `NODE`,
when `analytics_journal` is declared `CLUSTER`, or when one path is declared by
two owners; the rules live in `FsContext::declarationErrors()`, which a
project's unit test can call on its own context.

## Node Or Cluster

Two owners, as the two cases of `AgentScope`: a **node directory** is one per
node, and only the processes of that node read it; a **cluster directory** is
one per cluster, and any node reads it. The registration names the owner.

The test is one question: will a process of another node open a file from
this directory? An agent placed by POLICY moves between nodes, so its
directory answers yes.

`files`, `data_export` and `legal_export` are declared cluster directories,
whatever features the project declares; any of them declared `NODE` fails the
start. `files`, because
the library is one per cluster and puts a file in from its own node, while the
bytes are sent by X-Accel from the nginx of the node that holds the browser's
connection
([files-registry.md](files-registry.md), "Serving A File"). `data_export`,
because the export agent is placed by POLICY and on start removes every ready
row whose archive it cannot see (`AbstractDataExportAgent::onStart()`,
`framework/backend/DataExport/AbstractDataExportAgent.php`): on a node
directory a move of the agent silently wipes every ready copy; and the archive,
too, is sent by the nginx of the browser's node. `legal_export` for the same
two reasons: its agent is a cluster singleton that removes on start every ready
export whose file it cannot see, and the file is sent by the nginx of the
browser's node.

A temporary file is handed to another agent twice: the assembled upload
(`hilos_file_publish`, field `tmpIndex`) and the drawn image copy
(`hilos_image_rendered`, field `tmpIndex`), both to the files library, which
takes them with `storeFromTmp()` from its own node
(`framework/backend/Files/Storage/LocalFilesStorage.php`). Such a file lies
where the library's node sees it: the hand-over goes through a cluster
directory (not in the code yet — HIL-1241).

One path, one answer: names registered on one path are one directory with one
owner. Two owners on one path — the temporary directory counted too, under
`tmp` — fail the start, naming the names, the path and the owners. Paths are
compared as written, less trailing separators: a directory is created on first
use and may not exist at start; whether two paths are physically one directory
is the question of "The Guard" below.

What stays with the node and lives outside `$fs`: the log directory — one
daemon per directory, the owner on its own node ([logs.md](logs.md), "One
Daemon Per Log Directory"); `BACKUP_DIR` — an archive belongs to the node that
took it ([admin-feature-scaffold.md](admin-feature-scaffold.md), the paragraph
on `BACKUP_DIR`). The mirror image in one sentence: the marker of the log
directory (`.hilos-log-root-owner.json`, `LogRootOwnershipGuard` in
`framework/backend/Log/LogRootOwnershipGuard.php`) proves the directory is NOT
shared; the guard of a cluster directory proves that it is.

## One Disk In This Version

A cluster directory is kept on a disk: one machine, or one shared volume
mounted on every node. The volume is the installation's concern; the framework
does not carry files between nodes (declined at the HIL-303 interview,
27.09.2026).

The nginx of every node serves a cluster directory under the same internal
alias, because the X-Accel reply comes from the node that holds the browser's
connection. The demos show the shape: `data_export` is mounted at
`/data_export/` behind `location ^~ /__hilos_data_export/`
(`demo/chat/docker/nginx.conf.template`, the same in `demo/tasks` and
`demo/polls`), `legal_export` at `/legal_export/` behind
`/__hilos_legal_export/` (`HILOS_LEGAL_EXPORT_XACCEL_LOCATION`), and the chat's registry files at `/published/` behind
`/__hilos_files/` (`HILOS_FILES_XACCEL_LOCATION`).

Watching a cluster directory: a write from another machine may announce
nothing, and the periodic rescan covers it
([filesystem-watch.md](filesystem-watch.md), "Coalescing And The Period"; a
network volume without events — "What A Change Means Here"). Today nobody
watches a cluster directory — `BackupAgent` watches only its own `BACKUP_DIR`.

## The Guard

With several nodes, a node whose cluster directory is not the one its
neighbors see is not admitted into the cluster, and the refusal names the
directory (HIL-1242). Without it an installation without a shared volume learns
of that by a 404 on a download, or by ready copies gone after the export agent
moved.

**The marker.** Every cluster directory — tmp too, when declared `CLUSTER` —
carries the file `.hilos-cluster-directory.json` at its root: the format
version, a marker of 32 hex characters, the `CLUSTER_NODE_ID` of the node that
wrote it and when (`ClusterDirectoryMarker`,
`framework/backend/Fs/ClusterDirectoryMarker.php`). The marker is a random name
of the directory, not a digest of its content and not derived from anything: a
node with an empty directory of its own would derive the same name and pass.
The context lists the directories it covers with `clusterDirectories()` — tmp
first, then the registration order; two names on one path read one file.

**Who reads it, and when.** Only the start of a cluster node's daemon, once,
right after the database marker and before the peer port opens
([daemon-lifecycle.md](daemon-lifecycle.md), "The database both ends read");
workers and CLI commands never touch it, and a single-node installation neither
writes nor checks it. The first write is decided by exclusive creation
(`FsPath::createExclusive()`, O_EXCL): of nodes starting at once on an empty
directory one creates the file and every one of them reads its marker back. A
node that finds the file empty or half-written waits for the whole file, with a
line in the journal and no deadline; a file it cannot understand refuses its
start rather than being overwritten.

**On the handshake.** Each directory is the kind `directory:<name>` in the
`markers` field beside `database`, judged by the same rule on both ends of every
link: a node whose directory is its own — empty, so it wrote a marker of its
own, or another one, so it read another — is alone and admitted by nobody. Mount
the shared volume, restart the node, and it joins. The check is made on entry
only: a volume unmounted from a running node is not noticed until that node
restarts.

**The rule for code.** The marker is the framework's file, not the owner's.
Whatever empties a cluster directory, measures it or matches it against its own
rows lists it through `FsDirectory::entries()`, which leaves the marker out —
`deleteAll()`, `size()` and the data export sweep do. Listing a cluster
directory with `FsPath::entries()` and deleting what it names would remove the
marker, and the next node to restart would be refused by all the others.

**By hand.** The marker is removed only by hand, and removing it splits the
cluster on the next restarts: the node that starts next writes a new marker, and
every node still running refuses it. Restart all nodes at once, or put the file
back. A copy of a cluster directory brought from another installation carries
that installation's marker: switch all nodes at once, or remove the file before
the first node starts. Moving a cluster to the first build with this guard is
also all nodes at once — a node of the previous build names no directory marker
and is refused.

## Names, Never Paths

A frame, a signal and a database row carry the logical name of the directory
and the name of the file; the path is computed by the process that opens the
file, from its own `Hilos::$fs`.

This is so everywhere today. A `hilos_file_publish` item carries `tmpIndex`
(`framework/backend/Files/DTO/FilePublishItemData.php`), `hilos_image_rendered`
carries `tmpIndex`
(`framework/backend/Files/Image/DTO/ImageRenderedSignalData.php`),
`hilos_image_render` carries `storedName`
(`framework/backend/Files/Image/DTO/ImageRenderSignalData.php`); the rows hold
a name — `hilos_file.stored_name`, the archive name in `hilos_data_export`
(read in `AbstractDataExportAgent::onStart()`); the X-Accel reply carries the
location and the file name
(`framework/backend/DataExport/DataExportDownloadResponse.php`).

`getPath()` (`framework/backend/Fs/FsFile.php`,
`framework/backend/Fs/FsTmpFile.php`) is for the process that holds the file,
to hand the path to a library that needs one — GD, ZIP, a copy; the path never
leaves the process.

Why: a path is true only on the machine that computed it — a shared volume may
be mounted elsewhere on another node — and a storage that is not a disk has no
path at all.

## Other Storages Later

This version keeps cluster directories on a disk only. Not built:
S3-compatible storages (Amazon S3, Cloudflare R2, Backblaze, Hetzner,
self-hosted Garage, SeaweedFS, MinIO) and Azure Blob; serving such a file —
nginx proxies a short-lived signed link under our own address, so the browser
never sees the storage, the cookie is still checked by the agent, and the
storage may sit in a closed network (chosen on the epic HIL-1203); moving the
files already kept when the storage changes. The list waits as
`TODO(HIL-1203)` at two seams — `FsContext` and the registry's
`FilesStorageInterface`. The registry's storage seam already exists
(`Hilos::createFilesStorage()` in `framework/backend/Hilos.php`;
[files-registry.md](files-registry.md), "Storage"); `data_export` and every
X-Accel alias still assume a disk.

## What Is Not Here

- Carrying a file between nodes — declined (HIL-303, 27.09.2026).
- The daemon's own body sent through the nodes: without nginx in front, a
  browser on another node gets a 500 whose line names the env to set
  ([files-registry.md](files-registry.md), "Serving A File").
- Handing an upload between nodes, live on a stand — when the first cluster
  demo declares `UPLOADS` (no leaf).
- Storage drivers — the section above.

## Validation

The cluster stand of binance-btc-tracker shows: nodes sharing `data_export`
are admitted, a node with a directory of its own is refused, and a ready copy
survives the node the export agent lived on (not in the code yet — HIL-1243).
