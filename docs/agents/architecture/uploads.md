# Uploads: Receiving A File Over frame_binary

Read this before accepting a file from a browser in a project, declaring an
upload target, adding a check to one, reading a received upload as its
consumer, or changing what travels on the wire while a file is sent. The
machinery is `framework/backend/Files/Upload/`; the rows are the runtime
collection `hilosUploads`.

## Core Rule

A project receives files by declaring `HilosFeature::UPLOADS`, registering the
uploads agent pair, and naming its upload targets in `Hilos::UPLOAD_TARGETS`.
Nothing else writes an upload: `UploadsAgent` (`hilos_uploads`) is the one
truth source of `hilosUploads`, every other process reads its replica, and a
consumer takes a complete upload's file from there.

An upload lives on the **connection**, not on a page. The two upload actions
are the agent's own (`AGENT_ACTIONS`), and every `frame_binary` frame of a
project that declares the feature goes to the agent whatever page is open, so a
navigation inside the application does not cut an upload.

Do not add a page that routes `frame_binary` in a project that declares
`UPLOADS`: the frame is routed by type, and the activation validator refuses
the pair by the page's name. A project keeps one kind of upload — the chat
gave up its page upload for this feature (HIL-144) and is its first consumer,
with the one target `chat_attachment`
(`demo/chat/backend/Files/ChatAttachmentUploadTarget.php`).

## Activation

```php
protected const array FEATURES = [HilosFeature::UPLOADS /* , ... */];

public const array UPLOAD_TARGETS = [
    'gallery' => GalleryUploadTarget::class,
];

public const array AGENTS = [
    UploadsAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => UploadsAgent::class,
        AgentRegistryKey::DAEMON => UploadsAgentDaemon::class,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
    ],
];
```

There is nothing to subclass: a project's only say is its targets. The start
refuses the feature without the agent pair (and the pair without the feature),
without a target, with a target that does not extend `AbstractUploadTarget`,
with targets but no feature, beside a page routing `frame_binary`, and when the
FS context configures no tmp directory — chunks are kept there. Tmp is the
cluster's, so the library and a project's check read a complete upload from any
node ([filesystem.md](filesystem.md)).

## Targets And Checks

A target (`AbstractUploadTarget`) is the policy of one kind of file:
`maxBytes()`, `requiresSignIn()` and `visibility()` — no defaults: an action says out loud who may
perform it — and optionally `acceptedMimeTypes()` (exact types or `image/*`
masks; empty accepts any), `sniffsContent()` and `extraChecks()`.
`visibility()` declares who may read a published file of this type, such as
`FileVisibility::OWNER` for a private gallery. Publication cannot choose a
different audience for the same target. A project target name beginning with
`hilos_` is refused at startup; that prefix belongs to framework targets.
`Hilos::uploadTargets()` combines project and enabled framework targets.

Every check implements `UploadCheckInterface`: `checkDeclared()` judges the
declaration before any byte is accepted, `checkReceived()` the whole file
before the upload completes; a check with nothing to say at one moment answers
null there. The agent runs them in this order and the first refusal wins:

1. `SizeLimitCheck` — empty file, or above `maxBytes()`, asked of the target
   on every declaration: a limit read from a setting follows an
   administrator's change at once (the chat's `chat_attachment_max_file_bytes`);
2. `DeclaredMimeCheck` — a type that is not `type/subtype` after
   `UploadMime::normalize()`, or one outside a non-empty list;
3. `StorageLimitCheck` — only where the project declares `FILES`: the
   registry's files, every upload holding a file on any connection, and the
   declared size together exceed the setting `files.max_total_bytes`
   (`storage_limit`, `Storage limit would be exceeded`). Zero or less — the
   default — is no limit, and the setting is read on every declaration. Judged
   on the declaration alone: from then on the place is reserved. Two limits of
   it are known and left as they are:
   - *the files in flight.* On publication the uploads agent drops the upload's
     row before the files library writes the registry row, and for that moment
     nobody counts the file; a declaration in that window may take the storage
     past `files.max_total_bytes` by the files in flight. Come back when the
     limit becomes a quota somebody pays for — and decide then who holds the
     running sum as well: who holds the reservation and who holds the sum is
     one question;
   - *the price.* With the limit on, every declaration runs `SELECT SUM(size)`
     over `hilos_file` and `hilos_file_variant` and walks every upload, in the
     one process of the uploads agent. Come back with the first project that
     keeps a large registry. With the limit at zero there is no query at all;
4. `AllowedContentCheck` — only for a sniffing target with a list: the type
   read from the content is outside it;
5. the target's `extraChecks()`, in their order.

The framework ships one check for `extraChecks()`, off until a target adds it:

```php
public function extraChecks(): array
{
    return [new DuplicateContentCheck()];
}
```

`DuplicateContentCheck` fails a received file whose fingerprint the same person
already keeps — in a bound file of the registry
([files-registry.md](files-registry.md)) or in another complete upload of
theirs on any connection — with `duplicate_content`, `This file is already
uploaded`. Only the same person: a refusal over somebody else's file would tell
a stranger that such a file is kept. Only bound files: a row published but not
bound — the sending fell through, and the row waits for the sweeper
(`files.unbound_ttl_hours`, 24 by default) — is a file the person sees nowhere,
and a refusal over it is worse than a missed duplicate.

A guest's upload is not judged on arrival: a guest owns nothing to compare
with. Its draft is judged when it is published after the guest signed in — the
uploads agent asks the check again, for the person signed in now, and a match
refuses the whole request ([files-registry.md](files-registry.md),
*Publishing*). Two guest drafts of the same content in one request both pass:
neither is that person's yet when the other is judged.

The check reads the registry, so a project without `FILES` cannot create it,
and the uploads agent, which creates its checks when it starts, does not start.

A declared type that differs from the detected one is not a refusal by itself:
browsers declare `application/octet-stream` for everything they do not know.

## Declaring, Refusing, Sending

`hilos_upload_init {target, clientUploadId, filename, mimeType, size}` is a
tracked action; success means "send the chunks". It is refused, with no row
left behind, in this order: a malformed payload (the reader: an id outside
1–64 of `[A-Za-z0-9_-]`, an empty or over-255-character name once its path is
cut, a negative size); an unknown target; sign-in required on an anonymous
connection (401, the `AUTH_ACTIONS` refusal); the same id still ready or
uploading (a failed or complete one is replaced with its file); sixteen
uploads holding a file on the connection; a target check; a tmp file that
cannot be created.

Every chunk is a signed `frame_binary` frame (`UploadFrame`):

```
[1 byte L = 1..64][L bytes clientUploadId][file bytes]
```

A frame without a readable signature, or naming no ready/uploading upload of
its connection, is dropped with a debug line and creates nothing. More bytes
than declared fail the upload `size_overflow`; an append that fails,
`write_error`. Exactly the declared size first strips supported picture
metadata (below), then writes onto the row, in one write, the fingerprint of
the bytes that remain (`contentHash`, sha256 counted while the chunks arrived
if no bytes changed) and, for a sniffing target, the type read from the head
of the file with libmagic; then the received checks run, and the upload completes
or fails with the refusing check's code (`content_mismatch`,
`duplicate_content`, `storage_error` when the file cannot be read back, or a
project check's own).

Phases: `ready → uploading → complete | failed` (`UploadPhase`). A failed upload
keeps its row to say why and has no file; a complete one keeps its file in the
tmp directory until it is published into the files registry or goes.

## Picture Metadata

On the last byte, before type sniffing and the target's checks, the uploads agent
recognizes JPEG, PNG, and WebP by their content header. For these three formats
it writes a cleaned temporary file in bounded chunks and hashes those written
bytes. JPEG keeps image markers, JFIF, Adobe, each ICC profile part, and a
minimal EXIF Orientation tag when rotation is 2–8. It drops other APP markers,
comments, and everything after EOI, including a second frame or live-photo
video. PNG keeps critical chunks and listed rendering and animation chunks;
it replaces eXIf with only Orientation when needed and drops text, time,
private ancillary chunks, and bytes after IEND. Extended WebP keeps its image,
alpha, animation, and ICC chunks; it replaces EXIF with Orientation, drops XMP
and other chunks, and updates VP8X flags and RIFF size. Simple WebP passes
through. GIF, HEIC, AVIF, TIFF, PDF, and other formats pass through.

If the file was already clean, its original temporary file and running digest
stay. A malformed picture container also stays byte for byte, with an agent
warning naming the upload and reason; there is no new browser refusal. A disk
read, write, or replace failure yields `storage_error` and the existing
"Cannot finish upload" message. The temporary index and the browser's
declared size and progress do not change.

The content hash is the sha256 of the bytes that will be stored. This happens
at reception so the files library does not redo the work and a downloaded,
cleaned original uploaded again has the same fingerprint for duplicate checks.
Cleaned files are deterministic and a second pass changes nothing. The agent
is one cluster singleton: a large picture holds its turn for tens of
milliseconds per 10 MB. A project cannot opt out today; the owner-approved
TODO at `UploadsAgent::finish()` calls for a way to keep camera metadata for a
target such as a photo gallery, with stripping as the default (HIL-1171).

## What The Browser Sees

`hilos_upload_state` goes to the one connection on every change, always the
whole row: `{clientUploadId, phase, receivedBytes, declaredSize, errorCode,
errorMessage}`. A frame with `phase: null` and every other field null means
the upload is gone. A refused declaration never travels here — it is the
action's own error.

While chunks arrive the row and the frame are written at most once per
`PROGRESS_MIN_INTERVAL_SECONDS` (0.3 s); a change of phase is written and sent
at once. The row is not written per chunk because the collection is held in
every process and every write is a sync to all of them; the exact count between
writes lives in the agent's memory.

## The Browser Client

The framework-agnostic client lives in `@hilos/core`, under `uploads/`.
`bootHilos` binds its one application-wide instance to the connection, the
application's one tracked `ActionLifecycle`, and the current session user. There
is no feature flag on the client: a project that does not declare `UPLOADS`
receives no upload-state frames, and an attempted declaration is refused by the
server.

A project uses three exports:

- `uploadFile(target, file)` adds a browser `File` to the queue and returns its
  client-minted upload id;
- `cancelUpload(clientUploadId)` removes an unannounced queued file immediately,
  or sends the tracked cancel action once the server knows the upload;
- `hilosUploads` is the readonly signal of uploads, in selection order. Each
  record carries `clientUploadId`, `target`, `filename`, `declaredSize`,
  `receivedBytes`, `phase`, `errorCode`, `errorMessage`, and `canceling`.

Files run one at a time. The client does not declare the next file until its
turn, and does not stream until the declaration succeeds. It regards the first
`page_response` or `subscription_page_error` after a connection opens as the
readiness cue: actions route through that page subscription, so an open socket
alone is not enough. Each chunk contains at most 64 KiB of file bytes, and the
client waits while the socket's `bufferedAmount` is above 1 MiB rather than
letting the whole file accumulate in browser memory.

The server remains authoritative for every phase and received-byte count. The
client owns only `queued`, before a declaration; it replaces each public record
and the containing array when a state frame arrives. A server `failed` frame
stops the stream. A gone frame removes a complete, failed, or canceling upload;
for a ready or uploading one it leaves a failed record with `interrupted` and
`Upload interrupted`.

A connection drop stops the stream and returns ready, uploading, and complete
records to `queued` with zero received bytes. Once the replacement connection's
page answers, they are declared from the beginning under the same id; failed
records stay failed. A guest who signs in keeps the list and every declared
upload: on publication they become the files of the person signed in. When the
session user signs out, or one signed-in user is replaced by another, the client
sends cancellation for every upload known to the current connection and clears
the list, so a file chosen by one person is never replayed under another
identity. Navigation inside the SPA does neither: the client and its queue live
above pages.

The browser client does not apply target policy, publish a complete upload, or
draw progress, failures, pickers, or drop zones. Target checks and publication
belong to the server/project; presentation belongs to the consuming view.

## Cleanup

An upload goes **without** its file when it is handed over to the files
registry ([files-registry.md](files-registry.md), *Publishing*) — frame `gone`;
the temporary file is the files library's from then on, and nothing of the
upload's own touches it again.

An upload goes with its file when:

- the browser cancels it (`hilos_upload_cancel {clientUploadId}`, in any phase;
  canceling one that is already gone succeeds) — frame `gone`;
- the same id is declared again after it failed or completed;
- its connection is no longer among the live ones — silently, on the agent's
  sweep (at most once a second); a project with no connections collection
  skips this comparison;
- it has not changed for `UPLOAD_TTL_SECONDS` (an hour) — frame `gone`;
- the agent starts (a predecessor's uploads cannot continue) or stops (every
  connection still here gets `gone`).

## Not Here

- Publishing a received file into the registry — see
  [files-registry.md](files-registry.md).
- Drag and drop, the file picker and the upload list — HIL-140.
- Streaming a file without writing it to disk — HIL-142.
- Tmp files orphaned by a killed process are not swept.
- Metadata in GIF, HEIC, AVIF, TIFF, PDF, and other formats is not stripped.
  Letting a project keep picture metadata by choice is the TODO in
  `UploadsAgent::finish()`.

## Validation

`composer run test:framework:unit` (frame, MIME, checks, DTOs, activation,
the frame route, the tmp refusal, picture container stripping and orientation)
and `composer run test:framework:integration`
(`UploadsAgentIntegrationTest`: every refusal, chunks, sniffing, the
fingerprint, clean and malformed pictures, cancel, sweep, start and stop;
`FilePublishIntegrationTest`: clean stored bytes, their measured size and
fingerprint, the storage limit and duplicate check, beside publication itself).
