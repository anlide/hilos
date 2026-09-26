# Agent HTTP Routes: An HTTP Address Answered By An Agent

Read this before adding an HTTP address whose answer needs the database, the
files, or anything else the master may not do — a download, a preview, a
redirect decided by a row — or when a request to such an address hangs or answers 503. The
machinery is `AbstractAgent::AGENT_HTTP_ROUTES`, `HttpRouter::addAgentRoute()`,
`HttpClient`/`HttpServer` parking, and the `http_request` / `http_reply` signals
(HIL-138). The first address built on it is the files library's
`GET /_hilos/file` ([files-registry.md](files-registry.md)).

## Why

The daemon's HTTP server lives in the master process. A handler registered in
`httpRoutes()` runs there, per request, and the master may read neither the
database nor the files
([../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md)).
An address that has to look up a row or a session is therefore answered by an
agent in its worker, the way a CLI command is
([command-server.md](command-server.md), «Flow» — this is the same mechanism
over HTTP).

## Declaring An Address

```php
final class CatalogAgent extends AbstractAgent
{
    public const array AGENT_HTTP_ROUTES = [
        HttpConstants::METHOD_GET => ['/_project/cover'],
    ];

    public function onSignalHttpRequest(HttpRequestDTO $data, string $source, string $name): void
    {
        $item = Hilos::$db->catalogItems[(int)($data->query['id'] ?? 0)] ?? null;
        $this->replyToHttpRequest($item === null
            ? HttpReplyDTO::refusal($data, HttpConstants::HTTP_NOT_FOUND)
            : HttpReplyDTO::response($data, HttpConstants::HTTP_OK, [
                HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
            ], (string)json_encode(['cover' => $item->coverPath])));
    }
}
```

The key is a method (`GET`, `POST`, `PUT`, `DELETE`), the value a list of exact
paths: starting with `/`, no query string, no `{placeholder}` — parameters ride
the query, which the agent reads by name. One method and path has exactly one
agent; the topology refuses the start and names both when two declare it.
`Hilos::getHttpAgentRoutes()` is the computed map, and a demo's topology
snapshot pins it.

`DaemonManager::boot()` mounts every declared address after `/status` and
before `httpRoutes()`, so a project route on the same method and path replaces
the agent's, as it replaces `/status`. `HEAD` and undeclared methods get the
router's ordinary 404.

## The Trip

1. `HttpRouter::route()` matches an agent's address and, instead of a response,
   returns a `ParkedHttpRequest`: an `HttpRequestDTO` with a fresh correlation
   id, the method, the path, the query map, the session token the router
   already took from the header or the cookie (never the url —
   [../antipatterns/secret-in-query.md](../antipatterns/secret-in-query.md)), and
   the node holding the connection (null off a cluster). No headers travel.
2. `HttpClient` holds itself in its `HttpServer` under the correlation id,
   queues `http_request` (source DAEMON, name `"<METHOD> <path>"`) and parses no
   further request of that connection — a pipelined one waits in the buffer.
3. `SignalRouter` routes it to the declaring agent; placement moves it to the
   node the agent runs on. The worker writes `HTTP: took …` to the agent's
   journal and calls `onSignalHttpRequest()`.
4. The agent answers with `replyToHttpRequest()`: `HttpReplyDTO::response()` or
   `refusal($request, $status)` — a JSON `{"error": …}` with `no-store`. The body
   is binary and travels base64; the reader refuses one that is not.
5. `http_reply`, named by the correlation id, routes to the connection: here
   when the request came from this node, over the peer link
   (`peer_http_reply`) to the node named as its origin otherwise, which writes
   it without routing again. The master writes status, headers and body, adds
   `Connection` by the keep-alive rule, finishes the request's analytics row
   with the reply's status and the time since parking, and goes on parsing.

## When Nobody Answers

- **The master cannot hand it over** — no agent declares it, the agent is not
  placed, its node has no live link, its start was refused, or its worker died
  before the start was reported: 503, from the same two methods that refuse an
  undelivered command, so a new refusal site covers both. A worker that dies
  after the request reached a running agent answers nothing; the client's
  timeout below ends that one.
- **The handler threw** an `AgentException`: the worker answers 500.
- **The agent declared the address and never overrode the handler**: the
  default answers 500 and says so in the journal.
- **The browser left** while parked: the server drops the hold and tells the
  master through the command channel's `AbandonedCommandSink`, which drops a
  frame held for a starting agent. A reply that arrives later is a warning.

There is no server-side clock. An agent that neither answers nor throws — or
throws something other than an `AgentException`, or dies with its worker — is
caught by the client's own timeout — nginx's `proxy_read_timeout`, the
browser's — whose close is the event above.

## What Is Not Here

- Streaming, `Range`, and bodies larger than a frame should carry: the reply is
  one frame, through the master and in a cluster over the peer link (8 MiB write
  queue). A large file is nginx's to send — `X-Accel-Redirect` in the reply
  ([files-registry.md](files-registry.md), «Serving A File»).
- Request bodies and headers other than the session token — a webhook's POST
  body among them: no address that needs them is built yet.
- The analytics row is still written in the master when the request is routed —
  an existing violation of the master rule, recorded as P-415.
