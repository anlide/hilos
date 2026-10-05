# Attachment Serving

How an uploaded chat attachment is served back to the browser — images as a
thumbnail that opens the original, other files as a download. Upload itself is
in [file-upload-flow.md](file-upload-flow.md); this is the read/display path.
The chat has no route of its own for it: the files library serves every
registry file at `GET /_hilos/file`
([files-registry.md](../../../../docs/agents/architecture/files-registry.md),
"Serving A File"), and this file is what the chat decides on top of it.

## The four decisions

1. **Authorize by cookie**, the same session cookie the WebSocket uses. The URL
   carries only the registry file id (`/_hilos/file?id=123`, plus
   `variant=chat_thumb&v=…` for the thumbnail); the browser attaches the cookie
   to `<img src>` / `<a download>` automatically. Both addresses are built on
   the server by `HilosFiles::downloadPath()` and ride the feed row as `url`
   and `thumbUrl` — the thumbnail's carries the signature of its declaration,
   which only the server knows.
2. **Serve strictly same-origin.** `/_hilos/file` is reverse-proxied in every
   environment — an nginx `location = /_hilos/file` in test/prod (mirroring
   `/ws`), and in local Vite proxies to `chat-files-local` (`VITE_FILES_TARGET`).
   A separate public port/host for files is not allowed: cross-site the cookie
   would not ride without `SameSite=None` + CORS.
3. **nginx streams the bytes via `X-Accel-Redirect`.** Used on all chat stands,
   including local (and the full profile with the `/published` volume). With
   `HILOS_FILES_XACCEL_LOCATION=/__hilos_files` the library answers with an
   empty body and the redirect header; nginx's `internal` location
   `^~ /__hilos_files/` (`alias /published/`, the registry's files directory
   mounted read-only) streams the file. The daemon's direct body delivery
   (up to 4 MiB, docs/agents/architecture/files-registry.md, "Serving A File")
   is not used by any chat stand.
4. **Render by mime type.** `image/*` → the `chat_thumb` copy
   (`<img loading=lazy>`; 384×384 contain, WEBP, drawn by the images agent on
   its first request) inside a link to the original; everything else →
   `<a download>`. The library serves images `inline` and everything else as
   `attachment`, and the type the server read from the content reaches the
   frontend on the feed row (`mimeType`).

## Why not a query-token

A token in the URL is fatal here, not just untidy:

- it leaks into nginx/daemon access logs, browser history, and `Referer`;
- it is a second auth path divergent from the WebSocket's cookie auth;
- the session cookie is `httpOnly`, so JS physically cannot read the token to put
  it in a URL — a query-token simply stops working. Cookie auth is the only path
  that survives `httpOnly` (the browser sends the cookie itself).

## Per-object authorization

The chat publishes its attachments with `FileVisibility::AUTHENTICATED`, and its
`FilesLibraryAgent::grantsRead()` widens that by one rule: a file attached to a
message is served to **any** session — a guest's, or one that has expired —
because a guest reads the feed and sees its pictures. A request presenting no
session at all is refused with 401. The chat is global (no private messages),
so this suffices; revisit per-object authorization if private conversations
are added.
