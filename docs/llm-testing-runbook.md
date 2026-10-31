# LLM testing runbook — moderation and bots, local and external

A step-by-step walkthrough for driving the chat demo's LLM roles by hand:
framework moderation for messages, attached pictures, profile photos and own
names, plus the chat bots. It starts with a local Ollama, then an external
OpenAI-compatible API. Every step is a command to run or a thing to click; the
"why" is in
[llm-routing](agents/architecture/llm-routing.md) and
[moderation](agents/architecture/moderation.md), not here.

Three facts decide everything below, so read them first:

1. **Chat uses the framework moderation profiles.** Outbound messages use
   `hilos.moderation.text` as a `TEXT_WITH_FILES` check; the text model receives
   upload names, MIME types and sizes only. Each image attachment then gets its
   own `hilos.moderation.image` check from a JPEG copy, in attachment order.
   Profile photos and own-name changes use those same framework roles.
2. **Moderation settings override env defaults.** `moderation.text.*` and
   `moderation.image.*` (`provider`, `model`, `url`, `timeout_sec`) are editable
   at `/hilos/settings`. With no saved value, they use the corresponding
   `HILOS_MODERATION_TEXT_*` / `HILOS_MODERATION_IMAGE_*` environment default,
   then the framework catalog default. `moderation.check_timeout_sec` fixes the
   deadline for the whole outbound message chain.
3. **Credentials never live in settings.** `LLM_EXTERNAL_URL` and
   `LLM_EXTERNAL_API_KEY` come from env only. A setting may switch a profile's
   provider, never the key it uses. The chat's bot settings are separate:
   `chat_bot_*` may inherit their defaults from `default_bot_*`.

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
frontend iteration with HMR (the Vite dev server proxies `/ws` to the daemon,
matching the same-origin behavior of production nginx). The difference is HMR
versus the static compiled dist served by nginx.

### Three things that bite in the build stack

- **`demo/chat/.env` does not configure ports.** It is the container `env_file`.
  Compose *interpolation* (`${NGINX_HTTPS_PORT:-443}`, `${MYSQL_HOST_PORT:-33060}`)
  reads `demo/chat/docker/.env` — the file next to the compose file. Port
  overrides put in `demo/chat/.env` are silently ignored.
- **Published attachments are not mounted here.** The prod and test composes
  mount `../data/chat_attachments/published:/published:ro` into nginx for the
  daemon's `X-Accel-Redirect`; the local `full` profile does not, so attachment
  downloads 404 in this stack even though upload and moderation work.
- **The session cookie carries no `Secure` flag in this stack.** It follows
  `APP_ENV` and nothing else (HIL-582): `prod` and `staging` are secured, `dev`,
  `local` and `test` are not, so the plain-http dev stack works as it stands. To
  mirror production cookie behaviour, name the environment `staging` in
  `demo/chat/.env` and restart — there is no cookie switch of its own to flip.

### Reaching Ollama from the daemon container

The daemon uses `LLM_LOCAL_URL`, default `http://host.docker.internal:11434`. It
is also the address of a framework moderation role while both its
`HILOS_MODERATION_*_URL` env and its `moderation.*.url` setting are empty.

- **Docker Desktop (Windows/macOS/WSL2):** works out of the box.
- **Native Docker on Linux:** `host.docker.internal` does not resolve. Set
  `LLM_LOCAL_URL` to the bridge gateway (`http://10.196.0.1:11434`) in
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
foreach ([
    'hilos.moderation.image', 'hilos.moderation.text',
    'chat.bot', 'chat.analyzer', 'default',
] as $key) {
    $p = Hilos::$llm->resolve($key);
    printf("%-22s provider=%-8s url=%-34s model=%-14s timeout=%s apiKey=%s\n",
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

## 1. Local chat message moderation (Ollama)

The framework text profile checks the message text and attachment metadata; the
framework image profile checks each attached image separately. After a fresh
install both use the local provider: `qwen2.5:0.5b` for text and
`qwen2.5vl:3b` for images unless their settings or environment defaults were
changed.

1. **Pull and warm the vision model** for attached-picture checks. The composer
   pull script downloads the text models; pull the image model separately, then
   warm both roles. Ollama unloads a model after five minutes idle, so warm them
   again after a long pause:

   ```bash
   docker exec hilos-ollama-local-gpu-nvidia ollama pull qwen2.5vl:3b
   curl -s -m 180 http://127.0.0.1:11434/api/generate \
     -d '{"model":"qwen2.5:0.5b","prompt":"ok","stream":false}' > /dev/null && echo text-warm
   curl -s -m 180 http://127.0.0.1:11434/api/generate \
     -d '{"model":"qwen2.5vl:3b","prompt":"ok","stream":false}' > /dev/null && echo image-warm
   ```

   For the CPU container, use `hilos-ollama-local` instead of
   `hilos-ollama-local-gpu-nvidia`.

2. Open <https://localhost/> and follow the daemon log in a second terminal:

   ```bash
   docker logs -f chat-daemon-local
   ```

3. **Allow path.** Send a benign message ("Привет всем, как дела?"). The composer
   shows the *checking* phase, then the message appears in the conversation.
   Repeat with an ordinary image attachment: the text check receives metadata,
   then the image profile checks a rendered copy before the message is published.

4. **Block path.** Send an unmistakable insult. The message must NOT be published;
   the composer shows the rejection with its reason (`insult`), and the text stays
   in the input so it can be edited and re-sent. An image content refusal names
   that picture and also keeps its chip; attachment metadata alone is never a
   reason to block the text.

5. **Unavailable path.** Stop Ollama (`composer run ollama:stop`) and send a
   message: moderation must fail *visibly* (`service_unavailable`), not silently
   swallow the message. It is not a content refusal and does not send a rejection
   notification. Start Ollama again afterwards.

If a message stays in *checking*, check the profile print from step 0 and the
daemon log for an `LLM` exception. The fixed whole-chain wait ends at
`moderation.check_timeout_sec` (90 seconds by default).

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

Bot replies are not sent through the user-content moderation checks.

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

3. On `/hilos/settings` set the framework moderation profiles you want to test:

   | setting | value |
   |---|---|
   | `moderation.text.provider` | `external` |
   | `moderation.text.model` | `gpt-4o-mini` |
   | `moderation.image.provider` | `external` |
   | `moderation.image.model` | `gpt-4o-mini` |

   The image model must accept pictures. Set only the text rows to exercise
   message text, or only the image rows to exercise attached pictures and profile
   photos. Use `chat_bot_*` for bot replies, or `default_bot_*` for the bot's
   shared defaults.

4. Send the next message, change a profile name or photo, or trigger the next
   bot reply. Moderation resolves settings before each check; a check already
   with the model finishes on the profile it started with. Bot settings apply to
   its next LLM request. A settings edit needs no daemon restart.

5. Re-run the profile print from step 0 — expect `provider=external`,
   `url=https://api.openai.com`, `apiKey=(set)` for each changed profile — then
   repeat the message allow / block checks from section 1 and the profile photo
   or own-name checks from section 5. Latency is network-bound; there is no
   warm-up.

6. **Missing-key path.** Clear `LLM_EXTERNAL_API_KEY` and recreate the container
   as in step 2. With a profile's provider still set to `external`, its next
   request cannot resolve: moderation returns `service_unavailable` and does
   not publish a message; the bot logs the configuration error and publishes no
   reply. Restore the key and recreate the container before continuing.

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

> Only one external endpoint and key exist at a time (`LLM_EXTERNAL_*`), so the
> roles cannot point at two different vendors simultaneously, and switching
> vendors means editing `.env` and recreating the container. HIL-332 covers the
> named endpoint/credential catalog that would lift this.

## 5. Profile photo and own name — the framework moderator

The chat declares `HilosFeature::MODERATION`, so profile photos and own-name
changes use the shared framework checks: `hilos_profile_photo` asks
`hilos.moderation.image`, and `hilos_profile_name` asks
`hilos.moderation.text`. The people library publishes or refuses a photo only
after the verdict, leaving the previous photo visible until approval. The
session holder owns the admitted name operation through checking and applying,
until it is refused or the person owner replies.

Outbound messages use the same framework door. The `chat_message`
`TEXT_WITH_FILES` check sees the message and metadata for every ordered
attachment. Each image then receives a `chat_message_picture` `IMAGE` check
with a rendered JPEG copy under the same deadline; the message is published
only after the complete chain approves. Chat declares its kinds for the common
Moderation section and has no project-owned moderator or prompt-piece settings.

An own-name content refusal carries the server's reason sentence and
best-effort sends the warning notification *Your new name was not accepted*.
Unknown, incomplete or contradictory verdicts, unavailable checks or expired
deadlines show `Names cannot be checked right now.` without a notification. A
refusal before admission may arrive as `action_error`. An accepted refusal
remains in session state even after the original socket is gone. The standard profile window shows
`Checking your name…` during checking;
closing hides it while the operation continues. After F5, only that tab's open
checkpoint can restore after identity and session state are known, without
resending the name; a new tab does not open the window. See
[moderation](agents/architecture/moderation.md) for the
server lifecycle and [conflict resolution](agents/frontend/conflict-resolution.md)
for tab restoration and the window lifetime.

| | env (default of the setting) | setting | default |
|---|---|---|---|
| provider | `HILOS_MODERATION_TEXT_PROVIDER` | `moderation.text.provider` | `local` |
| model | `HILOS_MODERATION_TEXT_MODEL` | `moderation.text.model` | `qwen2.5:0.5b` |
| address | `HILOS_MODERATION_TEXT_URL` | `moderation.text.url` | empty → `LLM_LOCAL_URL` |
| per request | `HILOS_MODERATION_TEXT_TIMEOUT_SEC` | `moderation.text.timeout_sec` | 20 s |

`moderation.check_timeout_sec` (default 90) is the maximum whole-check wait,
including every image in an outbound message. Both the text and image profile
rows are on `/hilos/settings`; become an admin first (section 0).

### Local own-name check

1. Run the profile print from section 0 — expect `hilos.moderation.text` with
   `provider=local`, `model=qwen2.5:0.5b` by default, and the resolved local URL.
2. **Allow path.** In Profile → change your name, save a different benign name
   after the ordinary confirmation. The name changes after approval and appears
   in the live profile state.
3. **Refusal path.** Save a different name that clearly matches a built-in
   refusal category (insult, threat, hate speech, sexual content, spam or
   impersonation). The old name remains, the dialog receives the reason sentence,
   and a *Your new name was not accepted* warning notification is attempted.
4. **Unavailable path.** Stop Ollama (`composer run ollama:stop`) and submit a
   different name. The dialog receives `Names cannot be checked right now.`,
   the old name remains and no notification is sent. A check that reaches
   `moderation.check_timeout_sec` has the same outcome. Start Ollama again.

The text profile uses the same four env/default and settings rules as the image
profile below, with `HILOS_MODERATION_TEXT_*` and `moderation.text.*` keys.

| | env (default of the setting) | setting | default |
|---|---|---|---|
| provider | `HILOS_MODERATION_IMAGE_PROVIDER` | `moderation.image.provider` | `local` |
| model | `HILOS_MODERATION_IMAGE_MODEL` | `moderation.image.model` | `qwen2.5vl:3b` |
| address | `HILOS_MODERATION_IMAGE_URL` | `moderation.image.url` | empty → `LLM_LOCAL_URL` |
| per request | `HILOS_MODERATION_IMAGE_TIMEOUT_SEC` | `moderation.image.timeout_sec` | 20 s |

### Local photo check

1. **Pull the vision model once.** The composer pull script fetches only the three
   `qwen2.5` text models, not this one:

   ```bash
   docker exec hilos-ollama-local-gpu-nvidia ollama pull qwen2.5vl:3b
   ```

   Use `hilos-ollama-local` for the CPU container. Warm it as in section 1,
   step 1, with `"model":"qwen2.5vl:3b"`.

2. Run the profile print from section 0 — expect `hilos.moderation.image` with
   `provider=local`, `url=http://host.docker.internal:11434` (or your
   `LLM_LOCAL_URL`) and `model=qwen2.5vl:3b`.

3. **Allow path.** Open the profile page, choose *Change your photo*, pick an
   ordinary picture and save. The dialog shows `Checking your photo…`, then the
   avatar changes in every open tab.

4. **Block path.** Save a picture the built-in photo rules refuse (nudity or
   sexual content, graphic violence, hate symbols). The dialog shows the
   refusal (`This photo was not accepted: …`), the person gets a *Your new photo
   was not accepted* notification, and the old photo stays.

5. **Unavailable path.** Stop Ollama (`composer run ollama:stop`) and save a
   picture: the dialog says `Photos cannot be checked right now.` and no
   notification is sent, because nothing was said about the picture. A moderator
   that never answers ends the same way once `moderation.check_timeout_sec` has
   passed. Start Ollama again afterwards.

If the dialog stays on *Checking your photo…*, the request never reached the model
or the model tag is not pulled — check the profile print and the daemon log.

### External photo check

Credentials work as in section 3, steps 1–2: `LLM_EXTERNAL_URL` and
`LLM_EXTERNAL_API_KEY` in `demo/chat/.env`, container recreated. Then:

1. On `/hilos/settings` set **both** rows (the model tag is otherwise an Ollama
   one, as in section 3, step 3), using a model that accepts images:

   | setting | value |
   |---|---|
   | `moderation.image.provider` | `external` |
   | `moderation.image.model` | `gpt-4o-mini` |

   Putting `HILOS_MODERATION_IMAGE_PROVIDER=external` and
   `HILOS_MODERATION_IMAGE_MODEL=…` in `demo/chat/.env` does the same for a role
   whose rows were never saved; a saved row wins over env.

2. Save another photo. The moderator resolves the profile before each check, so
   the next check uses the new one with no restart; a check already with the
   model finishes on the profile it started with.

3. Re-run the profile print — expect `hilos.moderation.image` with
   `provider=external`, `url=https://api.openai.com`, `apiKey=(set)` — and repeat
   the allow / block checks above.

4. **Missing-key path.** Clear `LLM_EXTERNAL_API_KEY`, recreate the container, and
   leave the provider on `external`: every photo check ends as
   `service_unavailable` (`Photos cannot be checked right now.`) until the key is
   back or the provider is set to `local`.

### External own-name check

Credentials work as in section 3, steps 1–2. On `/hilos/settings`, set both text
profile rows:

| setting | value |
|---|---|
| `moderation.text.provider` | `external` |
| `moderation.text.model` | `gpt-4o-mini` |

The global `LLM_EXTERNAL_URL` and `LLM_EXTERNAL_API_KEY` supply the endpoint and
key. The profile resolves for each check, so the next name change uses the saved
settings without a daemon restart. Repeat the allow and refusal paths above. If
the key is missing, the name check returns `Names cannot be checked right now.`;
the name stays unchanged and no notification is sent.

## Troubleshooting

| Symptom | Cause to check first |
|---|---|
| Message stuck in *checking* | Ollama unreachable from the container, or a cold model load still running; the fixed whole-chain wait ends at `moderation.check_timeout_sec` |
| `Moderation is unavailable right now` | The model returned unparseable output, or the request errored — read the daemon log for the raw body |
| Settings change had no effect | An active model request keeps its resolved profile; a saved role setting overrides its environment default |
| External call fails immediately | Model name still an Ollama tag; or the URL is not `https://` |
| Moderation resolves `local` after setting `external` in `.env` | A saved `moderation.*` row overrides the environment default; check both provider and model for the role |
| Photo stuck on *Checking your photo…* | The moderator cannot reach the model, or `qwen2.5vl:3b` is not pulled; the wait ends as `service_unavailable` after `moderation.check_timeout_sec` (90 s by default) at the latest |
| `Photos cannot be checked right now.` | `service_unavailable`: model unreachable, model tag unknown to the provider, an answer that is not a verdict, or `external` selected without `LLM_EXTERNAL_API_KEY` — read the daemon log |
| A `moderation.image.*` edit changed nothing for a photo or attachment | The check had already started; the next check resolves the updated profile |
| `LLM_EXTERNAL_API_KEY` empty inside the container after editing `.env` | The container was restarted, not recreated — `env_file` is only read at creation. Run `up -d` (see section 3, step 2) |
