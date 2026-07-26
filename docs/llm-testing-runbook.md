# LLM testing runbook — moderation and bots, local and external

A step-by-step walkthrough for driving the chat demo's LLM roles by hand: the
moderator and the bots, first against a local Ollama, then against an external
OpenAI-compatible API. Every step is a command to run or a thing to click; the
"why" is in [llm-routing](agents/architecture/llm-routing.md), not here.

Two facts decide everything below, so read them first:

1. **For `chat.bot` and `chat.moderation` the settings row wins over the env
   variable.** `ChatLlmProfileOverrideSource` rebuilds both profiles from the
   settings; `CHAT_BOT_*` / `CHAT_MODERATION_*` env values are only the fallback
   when the router runs without the override source. Setting
   `CHAT_MODERATION_PROVIDER=external` in `.env` alone changes nothing.
2. **Credentials never live in settings.** `LLM_EXTERNAL_URL` and
   `LLM_EXTERNAL_API_KEY` are read from env only. A settings row may switch
   *which* provider is used, never the key it uses.

A settings row of `NULL` inherits: `chat_moderation_model` → `default_bot_model`
→ catalog default. So the shared `default_bot_*` rows move both roles at once.

## 0. Prerequisites, once per machine

```bash
# From the repo root. NVIDIA needs the Container Toolkit; use --profile ollama for CPU.
composer run ollama:start-gpu-nvidia
composer run ollama:pull-gpu-nvidia          # qwen2.5:0.5b, 3b, 7b (~7 GB)
```

Confirm the GPU was actually picked up — CPU inference works but is minutes per
reply:

```bash
docker logs hilos-ollama-local-gpu-nvidia 2>&1 | grep "inference compute"
```

Expect one line naming the card, `library=CUDA` and its VRAM. Then start the demo:

Then start the demo. **Test the production bundle, not the Vite dev server** —
the built app is what ships, and it exercises paths the dev server bypasses
(nginx-served static assets, same-origin `wss://` through the proxy, prerendered
routes, the SPA deep-link fallbacks):

```bash
cd demo/chat
composer run frontend:build        # vite build + prerender into frontend/dist
composer run daemon-start-build    # --profile full: adds nginx serving that dist
composer run daemon-status
docker stop chat-frontend-local    # optional: silence the dev server on :5173
```

`Status | ONLINE` means the daemon is up and migrations applied. The app is then
at **<https://localhost/>** (self-signed certificate — accept the browser
warning once; the same acceptance covers the `wss://` socket, since it is
same-origin). Plain `http://localhost/` 301-redirects to it.

Re-run `composer run frontend:build` after every frontend change: there is no
HMR in this stack, and nginx serves whatever is in `frontend/dist`.

The dev stack (`composer run daemon-start`, Vite on :5173) stays available for
frontend iteration, but it points the socket at the published port via
`VITE_WS_URL` instead of proxying — so a socket or asset problem seen there does
not necessarily match production, and vice versa.

### Three things that bite in the build stack

- **`demo/chat/.env` does not configure ports.** It is the container `env_file`.
  Compose *interpolation* (`${NGINX_HTTPS_PORT:-443}`, `${PHPMYADMIN_PORT:-8080}`)
  reads `demo/chat/docker/.env` — the file next to the compose file. Port
  overrides put in `demo/chat/.env` are silently ignored.
- **Published attachments are not mounted here.** The prod and test composes
  mount `../data/chat_attachments/published:/published:ro` into nginx for the
  daemon's `X-Accel-Redirect`; the local `full` profile does not, so attachment
  downloads 404 in this stack even though upload and moderation work.
- **The session cookie has no `Secure` flag by default.**
  `HILOS_SESSION_COOKIE_SECURE` defaults to false so the plain-http dev stack
  works. Under TLS, set it to `true` in `demo/chat/.env` and restart if the run
  is meant to mirror production cookie behaviour.

### Reaching Ollama from the daemon container

The daemon uses `LLM_LOCAL_URL`, default `http://host.docker.internal:11434`.

- **Docker Desktop (Windows/macOS/WSL2):** works out of the box.
- **Native Docker on Linux:** `host.docker.internal` does not resolve. Set
  `LLM_LOCAL_URL` to the bridge gateway (`http://172.26.0.1:11434`) in
  `demo/chat/docker/.env` — the compose interpolation file, not `demo/chat/.env`,
  because the daemon's `environment:` block re-declares this one variable and
  would shadow the `env_file` value.

Verify from inside the container:

```bash
docker exec chat-daemon-local sh -c 'php -r "echo @file_get_contents(\"http://host.docker.internal:11434/api/tags\") ?: \"FAILED\";"'
```

### Print the profiles the daemon actually resolved

The single most useful check — it applies env, catalog and settings exactly like
an agent does, and it is the fastest way to see whether a switch took effect:

```bash
docker exec -i chat-daemon-local php /dev/stdin <<'PHP'
<?php
require '/app/vendor/autoload.php';
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Core\Bootstrap\EntrypointPrelude;
EntrypointPrelude::run(Hilos::class, '/app', static fn() => Database::initialize(initHilos: true));
foreach (['chat.moderation', 'chat.bot', 'chat.analyzer', 'default'] as $key) {
    $p = Hilos::$llm->resolve($key);
    printf("%-16s provider=%-8s url=%-34s model=%-14s timeout=%s apiKey=%s\n",
        $p->key, $p->provider->value, $p->url, $p->model, $p->timeoutSec,
        $p->apiKey === null ? 'null' : '(set)');
}
PHP
```

### Become an admin

The settings and bots pages are behind an `ACCESS` guard on `User::admin`. Join
the chat once in the browser so a user row exists, note the generated name, then:

```bash
docker exec chat-mysql-local sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "update user set admin = 1 where name = '"'"'User8556'"'"';" hilos-demo-chat'
```

The guard re-checks per delivery, so the admin menu appears without a re-login.

## 1. Local moderation (Ollama)

The default state after a fresh install: `default_bot_provider = local`,
`default_bot_model = qwen2.5:3b`, both `chat_moderation_*` rows `NULL`.

1. **Warm the model** so the first message does not look frozen. A cold load of
   3b takes ~40 s on GPU; a warm request answers in ~0.2 s. Ollama unloads the
   model after 5 minutes idle, so warm it again if the machine sat unused:

   ```bash
   curl -s -m 180 http://127.0.0.1:11434/api/generate \
     -d '{"model":"qwen2.5:3b","prompt":"ok","stream":false}' > /dev/null && echo warm
   ```

2. Open <https://localhost/> and follow the daemon log in a second terminal:

   ```bash
   docker logs -f chat-daemon-local
   ```

3. **Allow path.** Send a benign message ("Привет всем, как дела?"). The composer
   shows the *checking* phase, then the message appears in the conversation.

4. **Block path.** Send an unmistakable insult. The message must NOT be published;
   the composer shows the rejection with its reason (`insult`), and the text stays
   in the input so it can be edited and re-sent.

5. **Rename path.** Profile → change the display name to something abusive. The
   same moderator handles it through a separate request type; expect a rejected
   rename with a retryable state.

6. **Unavailable path.** Stop Ollama (`composer run ollama:stop`) and send a
   message: moderation must fail *visibly* (`service_unavailable`), not silently
   swallow the message. Start Ollama again afterwards.

If a message hangs in *checking* forever, the request never reached the model —
check the profile print from step 0 and the daemon log for an `LLM` exception.

## 2. Local bots

Bots are seeded inactive (`bot.active = 0`), so nothing generates until you turn
one on.

1. Go to `/hilos/admin_bots`, open a bot (the three leaders — Marcus, Elena,
   David — react without a topic match and are the fastest to see) and set it
   active.
2. Write a message in the conversation and wait out the bot's
   `reaction_delay_min..max` seconds. The reply is generated through the
   `chat.bot` profile.
3. While it generates, `docker exec hilos-ollama-local-gpu-nvidia ollama ps`
   shows which model is resident and whether it sits on GPU or CPU.

Note that bot output is not moderated by default (`CHAT_MODERATION_BOTS=false`).

## 3. External provider — OpenAI

The external client is OpenAI-compatible: `POST {base}/v1/chat/completions` with
`Authorization: Bearer <key>`. **TLS is hardcoded**, so the URL must be an
`https://` endpoint — an http mock on localhost cannot be used.

1. Put the credentials in `demo/chat/.env` (gitignored; the daemon reads it via
   `env_file`, and no `environment:` entry shadows these two keys):

   ```
   LLM_EXTERNAL_URL=https://api.openai.com
   LLM_EXTERNAL_API_KEY=sk-...
   ```

   The URL's path defaults to `/v1` when omitted. For a provider that serves
   under a path, give it in full (e.g. `https://openrouter.ai/api/v1`).

2. **Recreate** the daemon container so it picks up the new env — `daemon-restart`
   is not enough. `env_file` is read when the container is created, so a restart
   reuses the old environment and the key silently stays empty:

   ```bash
   cd demo/chat && docker compose -f docker/docker-compose.local.yml --profile full up -d chat-local
   ```

   Verify before going further — this prints the length, never the key:

   ```bash
   docker exec chat-daemon-local sh -c 'echo "url=$LLM_EXTERNAL_URL keylen=${#LLM_EXTERNAL_API_KEY}"'
   ```

3. On `/hilos/settings` set **both** rows for the role under test:

   | setting | value |
   |---|---|
   | `chat_moderation_provider` | `external` |
   | `chat_moderation_model` | `gpt-4o-mini` |

   Changing only the provider sends `qwen2.5:3b` — an Ollama tag — to the
   external API, which answers with a model error. Use `chat_bot_*` for the bot,
   or `default_bot_*` to move both roles at once.

4. **Restart the daemon again.** Agents resolve their profile in the constructor,
   so a settings change does not take effect until the agent is recreated
   (HIL-331 tracks hot reload).

5. Re-run the profile print from step 0 — expect `provider=external`,
   `url=https://api.openai.com`, `apiKey=(set)` — then repeat the allow / block /
   rename checks from section 1. Latency is network-bound; there is no warm-up.

6. **Missing-key path.** Clear `LLM_EXTERNAL_API_KEY`, restart, and watch what a
   user sees: the profile cannot resolve and the agent constructor throws
   `LLMConfigurationException`. Confirm the failure is visible in the UI and not
   just in the log.

### Where the requests show up

OpenAI's console splits its request log by surface and by project, and both
catch people out:

- The client calls `/v1/chat/completions`, so the requests appear under
  **Completions**, not under Responses (a different API with its own tab).
- API keys belong to a **project**, and the console shows logs and usage for the
  project selected at the top. In any other project the same key looks unused.
  The response headers name the one that was billed — `openai-project`, next to
  `x-request-id`, which is also how a single call is looked up.
- Usage is counted even when request bodies are not stored, so a non-zero usage
  figure with an empty log means storage is off for that project, not that the
  request went somewhere else.

## 4. External provider — Anthropic

Anthropic is reachable through its OpenAI-compatibility endpoint, so the same
client can drive it. Confirm the endpoint answers before touching the demo —
this compatibility layer is not part of the framework's own test surface:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.anthropic.com/v1/chat/completions \
  -H "Authorization: Bearer $ANTHROPIC_API_KEY" -H 'Content-Type: application/json' \
  -d '{"model":"claude-haiku-4-5-20251001","messages":[{"role":"user","content":"ping"}],"max_tokens":8}'
```

A `200` means the layer works with that key and model; then configure exactly as
in section 3 with:

```
LLM_EXTERNAL_URL=https://api.anthropic.com
LLM_EXTERNAL_API_KEY=sk-ant-...
```

and set the role's model setting to the Claude model id. A `404` means the key or
account has no access to the compatibility endpoint — use OpenAI or an
OpenAI-compatible gateway instead.

**Use `claude-haiku-4-5`, and not only because it is the cheapest** ($1 / $5 per
million tokens against Sonnet's $3 / $15). `ModeratorAgent` sends
`temperature: 0.0` on every request, and Anthropic removed the sampling
parameters on its newer models — Opus 4.7 and later reject `temperature`
outright, and Sonnet 5 rejects any non-default value. Haiku 4.5 still accepts
them. Until the agent stops sending `temperature`, it is the only Claude model
this client can drive.

> Only one external endpoint and key exist at a time (`LLM_EXTERNAL_*`), so the
> roles cannot point at two different vendors simultaneously, and switching
> vendors means editing `.env` and recreating the container. HIL-332 covers the
> named endpoint/credential catalog that would lift this.
>
> Changing an LLM setting needs a daemon restart either way: an agent resolves
> its profile in its constructor, so a saved setting reaches it only on the next
> start (HIL-331).

## Troubleshooting

| Symptom | Cause to check first |
|---|---|
| Message stuck in *checking* | Ollama unreachable from the container, or a cold model load still running (up to ~40 s for 3b, longer for 7b) |
| `Moderation unavailable` | The model returned unparseable output, or the request errored — read the daemon log for the raw body |
| Settings change had no effect | Daemon not restarted, or the row edited was `chat_*` while the effective value comes from `default_bot_*` (or the reverse) |
| External call fails immediately | Model name still an Ollama tag; or the URL is not `https://` |
| Everything resolves `local` after setting `external` in `.env` | Expected — the settings row is authoritative for bot and moderation |
| `LLM_EXTERNAL_API_KEY` empty inside the container after editing `.env` | The container was restarted, not recreated — `env_file` is only read at creation. Run `up -d` (see section 3, step 2) |
