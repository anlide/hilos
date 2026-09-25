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
3. `AllowedContentCheck` — only for a sniffing target with a list: the type
   read from the content is outside it;
4. the target's `extraChecks()`, in their order — the point where HIL-136
   connects the storage limit and duplicates.

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
`write_error`. Exactly the declared size runs the received checks — a sniffing
target first writes the detected type onto the row, reading the head of the
file with libmagic — and completes the upload, or fails it with the refusing
check's code (`content_mismatch`, `storage_error` when the file cannot be read
back, or a project check's own).

Phases: `ready → uploading → complete | failed` (`UploadPhase`). A failed upload
keeps its row to say why and has no file; a complete one keeps its file in the
tmp directory until its consumer takes it.

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

## Cleanup

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

- Publishing a received file to storage and the `hilos_file` row, the storage
  limit and duplicates — HIL-136, through `extraChecks()` and the complete rows.
- The browser half — building signed chunks, the queue, reading
  `hilos_upload_state` — HIL-139; drag and drop — HIL-140.
- Moving the chat onto this feature — HIL-144.
- Streaming a file without writing it to disk — HIL-142.
- Placing the agent so chunks stay on one node: it runs as an ordinary cluster
  singleton. Tmp files orphaned by a killed process are not swept.

## Validation

`composer run test:framework:unit` (frame, MIME, checks, DTOs, activation,
the frame route, the tmp refusal) and `composer run test:framework:integration`
(`UploadsAgentIntegrationTest`: every refusal, chunks, sniffing, cancel, sweep,
start and stop).
