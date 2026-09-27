# Image Variants: Copies Drawn On The First Request

Read this before declaring image variants, changing their renderer, or tracing
a variant request that serves the original instead. The machinery is
`framework/backend/Files/Image/`; the rows are `hilos_file_variant`, owned by
the [files library](files-registry.md) beside `hilos_file`.

## Core Rule

The images agent draws a copy into a temporary file. Only the files library
keeps it in storage and writes its row. Publication leaves the original alone
and draws nothing: the first request for a variant starts its render.

## Activation

Declare `IMAGES` on top of `FILES`, with the files feature's migrations,
library and storage already configured. Register the renderer pair and name
at least one variant:

```php
protected const array FEATURES = [HilosFeature::FILES, HilosFeature::IMAGES /* , ... */];

public const array AGENTS = [
    // The files library and other required agents are registered here too.
    ImagesAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => ImagesAgent::class,
        AgentRegistryKey::DAEMON => ImagesAgentDaemon::class,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
    ],
];

public const array IMAGE_VARIANTS = [
    'catalog_thumb' => [
        ImageVariant::WIDTH => 384,
        ImageVariant::HEIGHT => 384,
        ImageVariant::FIT => ImageFit::CONTAIN,
        ImageVariant::FORMAT => ImageFormat::WEBP,
    ],
];
```

Names are 1–64 lowercase letters, digits or underscores; the `hilos_` prefix is
reserved for framework variants. Each side is an integer in 1–4096. The fit is
`ImageFit::CONTAIN` or `COVER`; the format is `ImageFormat::WEBP`, `JPEG` or
`PNG`, with WEBP when omitted. Extra declaration keys are refused.

Startup collects all activation errors: missing FILES or agent pair, variants
without IMAGES, IMAGES without variants, malformed declarations, a worker
outside the `ImagesAgent` family, or an engine that cannot write the declared
formats. The default needs PHP GD with the relevant output codecs. A project
without IMAGES needs no GD; the demos do not declare IMAGES yet.

## The Address

Build it with `HilosFiles::downloadPath($fileId, 'catalog_thumb')`:

```text
/_hilos/file?id=N&variant=catalog_thumb&v=0123abcd
```

`v` is the first eight hex characters of sha256 over the frame, fit, format
and renderer revision. Changing the declaration changes the address so a
year-long browser cache no longer names the old copy. The request handler does
not read `v`: the current declaration decides which copy is live. An unknown
variant is a project error at the builder, and a 404 at the HTTP address. The
address also returns 404 when IMAGES is not declared. Without `variant`, the
original download behaves as before.

## The Request's Trip

1. The library checks the variant before reading the database, then judges the
   original's id, row and access as for an ordinary download, including the
   project's `grantsRead()`. Refusals are the same 404, 401 and 403.
2. A source type outside JPEG, PNG, WEBP and GIF is served as the original with
   a short cache. Otherwise the library asks for a **live copy**: a row for
   `(fileId, variant)` with the current signature **and** bytes in storage.
   `liveCopy()` is the one definition, used here and on both result paths.
3. No live copy: `hilos_image_render` carries the whole `HttpRequestDTO`, file
   id, storage name, MIME type, variant and `retry: false` to the images agent.
   The library retains no waiting request of its own and answers nothing yet.
4. The images agent groups requests by `(fileId, variant, signature)`. A tick
   draws one whole queued key, with all its waiting requests, in a
   [monopolistic worker](../antipatterns/child-process-for-long-work.md).
5. `hilos_image_rendered` returns every waiting request with one of four
   outcomes: `rendered`, `ready`, `failed`, `missing`. A rendered result carries
   the temporary file index, encoded type and byte count. The library stores
   it under a new random name and creates or replaces its row, deleting the
   previous copy's bytes after replacement. A live copy already there wins:
   the extra temporary file is deleted and the existing row stays untouched.
6. The library answers each waiting request through the ordinary
   [agent HTTP reply](agent-http-routes.md) door. Rights are not judged again:
   they were decided before the request left the library.

If the original row vanished while rendering, the temporary copy is deleted
and every waiting request gets 404. A storage, database or field-validation
failure while keeping the copy is logged; its temporary and new storage files
are removed, and the requests receive the original with a short cache. A
missing original on disk returns 404 with a warning naming its row.

Waiting requests live only in the travelling frames and the renderer's queue.
If that worker dies, it takes the queue with it; the client's timeout closes
the parked HTTP requests. There is no library wait map and no server-side
timer added to the HTTP mechanism.

## What The Renderer Remembers

Two memories, each at most 1024 keys, last until restart; the oldest key goes
when a memory fills:

- **Failed pictures:** unreadable headers, more pixels than the ceiling, or a
  decoding or transformation refusal. The agent logs a warning once and
  answers later requests `failed` without rendering. A retry does not bypass
  this memory.
- **Handed-over copies:** a key whose rendered result was sent to the library.
  This is a hint about a frame, never truth about storage. A late ordinary
  request gets `ready`, so requests sent before the library kept the first
  result do not start another render.

On `ready`, the library checks `liveCopy()` again. No live copy means another
`hilos_image_render` carrying `retry: true`. A retry removes the handed-over
hint and queues the request or joins the already queued key. **It never
answers ready to a retry**, so any one request reaches the renderer at most
twice.

That one rule recovers from a failed store, swept copies of a linked original,
missing copy bytes and a rendered frame lost with a dead library. No save
acknowledgement and no reset frame from a starting library exist. Retries in
different ticks can draw twice; the library discards the second result once
the first copy is live.

Failure to create, write or measure a temporary copy is a failure of the
attempt, not a verdict on the picture. The agent logs an error and answers
`failed`, but remembers nothing: a full temporary volume must not pin a
repaired installation to the original until the agent restarts.

## The Engine And Its Limits

`ImagesAgent::createEngine()` returns an `ImageEngineInterface`, GD by default.
A project replaces it by subclassing the agent and registering that worker.
The startup validator asks the same static factory and its `unusableReason()`
before the agent starts. No provider name or engine setting is needed.

GD reads dimensions before allocating pixels. More than 40,000,000 pixels is
refused; the agent raises its worker's `memory_limit` to 512M while running and
restores the previous value on stop, since the freed worker may host another
agent. JPEG EXIF orientations 1–8 are applied before resizing, including the
reflections. CONTAIN fits the frame; COVER crops centrally to its proportions;
neither enlarges the original. WEBP and PNG keep alpha, JPEG composites on
white. WEBP quality is 82, JPEG 85 and PNG compression 6. An animated input
becomes a still copy.

`ImageRenderException` means the image cannot be decoded or transformed and
enters the failed-picture memory. `FsException`, including `FileWriteException`
from the encoder, means the attempt could not write its copy and stays out of
that memory. Implementations of the engine must preserve this distinction.

## Responses, Cleanup And The Storage Limit

A copy gets its own MIME type, `inline`, the original filename with the copy's
extension, ASCII and UTF-8 names, and `nosniff`. It uses the original's public
or private one-year immutable cache and the same transports: X-Accel or a
direct body up to 4 MiB. A fallback original keeps its ordinary download
headers but uses `max-age=3600` without `immutable`, so engine improvements
reach a browser within an hour.

The library's janitor removes copy rows first, then the original row, then
their files. If a project's foreign key keeps the original, it is marked
bound, but the copy files are still removed: their rows already went. Those
copies will be drawn on the next request. The variant foreign key's
`ON DELETE CASCADE` is a safeguard, not the janitor's normal path.

The files collection counts original and copy sizes in one query. The uploads
agent uses that same total plus uploads still holding bytes; it acquires no
new collection read or copy state of its own.

## Cluster Boundary

HTTP replies retain the request's origin node, so the library may answer a
browser on another node. Rendering still requires access to the original's
storage, and handing the result over requires a shared temporary directory.
With local directories this means the images agent and the library must run
on the same node. Their automatic co-placement is not implemented; POLICY
entries alone do not guarantee it, just as for uploads and publication.

## What Is Not Here

- Chat thumbnails and GD in its image — HIL-144; avatar variants and a person's
  crop choice — HIL-301.
- Original metadata removal — HIL-1171. Copies carry no source metadata; the
  original is unchanged. GD does not preserve color profiles.
- HEIC, TIFF, AVIF, BMP or SVG decoding in GD; those originals are served as
  fallbacks. The HTTP source-type gate currently admits only JPEG, PNG, WEBP
  and GIF, even with a replaced engine.
- Pre-generation, user-driven editing, a general publication door for files
  created outside uploads, or dimensions stored on the registry row.
- Cleanup of temporary files from lost result frames. They remain in tmp as
  lost upload-publication files do; there is no framework tmp janitor yet.

## Validation

Run `composer run test:framework:unit` for declarations, DTO roundtrips and GD
geometry; `composer run test:framework:integration -- --filter ImageVariantIntegrationTest`
for the complete library–renderer trip and its failure recovery. The chat's
`ChatTopologyRegistryTest` pins the library's incoming result frame even though
chat does not activate the renderer yet.
