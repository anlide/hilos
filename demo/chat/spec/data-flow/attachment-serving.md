# Attachment Serving

How an uploaded chat attachment is served back to the browser — images inline,
other files as a download. Upload itself is in
[file-upload-flow.md](file-upload-flow.md); this is the read/display path.

## The four decisions

1. **Authorize by cookie**, the same `hilos_session_token` the WebSocket uses.
   The URL carries only the attachment `id` (`/chat/attachment?id=123`); the
   browser attaches the cookie to `<img src>` / `<a download>` automatically.
2. **Serve strictly same-origin.** `/chat/attachment` is reverse-proxied in every
   environment — an nginx `location` in test/prod (mirroring `/ws`) and the Vite
   `server.proxy` in dev. A separate public port/host for files is not allowed:
   cross-site the cookie would not ride without `SameSite=None` + CORS.
3. **nginx streams the bytes via `X-Accel-Redirect`.** PHP only authorizes and
   sets `Content-Type` / `Content-Disposition`, returning an empty body plus the
   redirect header; nginx's `internal` location streams the file from disk
   (sendfile / Range / caching for free). This fits the buffered `body: string`
   response contract and keeps file I/O off the event loop. In dev (no nginx) the
   daemon serves the bytes directly — an environment-specific transport, not a
   stopgap.
4. **Render by mime type.** `image/*` → `<img loading=lazy>` (inline);
   everything else → `<a download>`. The backend already sets
   `Content-Disposition: inline` for images and `attachment` otherwise, and the
   attachment's `mimeType` already reaches the frontend on its entity.

## Why not a query-token

A token in the URL is fatal here, not just untidy:

- it leaks into nginx/daemon access logs, browser history, and `Referer`;
- it is a second auth path divergent from the WebSocket's cookie auth;
- the session cookie is `httpOnly`, so JS physically cannot read the token to put
  it in a URL — a query-token simply stops working. Cookie auth is the only path
  that survives `httpOnly` (the browser sends the cookie itself).

## Per-object authorization

The handler checks a valid session only — any logged-in user can fetch any
attachment by id. The chat is global (no private messages), so a session check
suffices; revisit per-object authorization if private conversations are added.
