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
keeps its page upload until HIL-144 moves it here.

## Activation

```php
protected const array FEATURES = [HilosFeature::UPLOADS /* , ... */];

public const array UPLOAD_TARGETS = [
    'avatar' => AvatarUploadTarget::class,
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
FS context configures no tmp directory — chunks are kept there.

## Targets And Checks

A target (`AbstractUploadTarget`) is the policy of one kind of file:
`maxBytes()`, `requiresSignIn()` — no default, an action says out loud who may
perform it — and optionally `acceptedMimeTypes()` (exact types or `image/*`
masks; empty accepts any), `sniffsContent()` and `extraChecks()`.

Every check implements `UploadCheckInterface`: `checkDeclared()` judges the
declaration before any byte is accepted, `checkReceived()` the whole file
before the upload completes; a check with nothing to say at one moment answers
null there. The agent runs them in this order and the first refusal wins:

1. `SizeLimitCheck` — empty file, or above `maxBytes()`;
2. `DeclaredMimeCheck` — a type that is not `type/subtype` after
   `UploadMime::normalize()`, or one outside a non-empty list;
3. `StorageLimitCheck` — only where the project declares `FILES`: the
   registry's files, every upload holding a file on any connection, and the
   declared size together exceed the setting `files.max_total_bytes`
   (`storage_limit`, `Storage limit would be exceeded`). Zero or less — the
   default — is no limit, and the setting is read on every declaration. Judged
   on the declaration alone: from then on the place is reserved;
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
already keeps — in a published file ([files-registry.md](files-registry.md)) or
in another complete upload of theirs — with `duplicate_content`, `This file is
already uploaded`. Only the same person: a refusal over somebody else's file
would tell a stranger that such a file is kept. A guest's upload is not judged.
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
`write_error`. Exactly the declared size first writes onto the row, in one
write, the fingerprint of the whole file (`contentHash`, sha256 counted while
the chunks arrived) and, for a sniffing target, the type read from the head of
the file with libmagic; then the received checks run, and the upload completes
or fails with the refusing check's code (`content_mismatch`,
`duplicate_content`, `storage_error` when the file cannot be read back, or a
project check's own).

Phases: `ready → uploading → complete | failed` (`UploadPhase`). A failed upload
keeps its row to say why and has no file; a complete one keeps its file in the
tmp directory until it is published into the files registry or goes.

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
records stay failed. When the session user changes, the client sends cancellation
for every upload known to the current connection and clears the list, so a file
chosen by one person is never replayed under another identity. Navigation inside
the SPA does neither: the client and its queue live above pages.

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
- Moving the chat onto this feature — HIL-144.
- Streaming a file without writing it to disk — HIL-142.
- Placing the agent so chunks stay on one node: it runs as an ordinary cluster
  singleton. Tmp files orphaned by a killed process are not swept.

## Validation

`composer run test:framework:unit` (frame, MIME, checks, DTOs, activation,
the frame route, the tmp refusal) and `composer run test:framework:integration`
(`UploadsAgentIntegrationTest`: every refusal, chunks, sniffing, the
fingerprint, cancel, sweep, start and stop; `FilePublishIntegrationTest`: the
storage limit and the duplicate check, beside publication itself).
