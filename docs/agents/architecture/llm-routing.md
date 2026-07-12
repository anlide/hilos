# Architecture: LLM Routing

Read this before touching `framework/backend/LLM`, before adding an agent that
talks to an LLM, or before changing how a provider is chosen. This is the
normative design the bot-agents Phase-1 leaves build against (routing engine,
runtime bot-agent abstraction, Ollama hardening, per-token external API). It is
the ADR that replaces the single global provider switch with a named-profile
routing model.

**Status: design (spike output, HIL-256).** The contract below is decided; the
engine that implements it is HIL-257. Where a name is given (`LlmProfile`,
`LlmRouter`, `LLM_PROFILES`) it is the target name, not a claim the class
already exists.

## Why this exists

Today provider selection is a single process-wide env switch. `ClientFactory`
reads `LLM_CHAT_PROVIDER` and returns one provider for the whole daemon:

```php
$provider = Hilos::$env[EnvConstants::LLM_CHAT_PROVIDER];
return $provider === LLMConstants::PROVIDER_EXTERNAL
    ? self::createExternalChatProvider()   // AsyncOpenAIChatProvider
    : self::createLocalChatProvider();     // AsyncOllamaChatProvider
```

That is wrong for bot-agents: different tasks want different backends at the
same time — a cheap local model moderates every message, a stronger external
model writes a bot reply, an analyzer summarises context. The chat demo already
needs this and works around the missing framework layer by re-implementing the
choice **three times**, each keyed on a different config source:

- `BotAgent` — keyed on setting `CHAT_BOT_PROVIDER`.
- `ModeratorAgent` — keyed on setting `CHAT_MODERATION_PROVIDER`.
- `ChatContextAnalyzerAgent` — keyed on env `CHAT_CONTEXT_ANALYZER_PROVIDER`.

Each duplicates `provider === external ? createChatClient() :
createChatClientWithConfig(url ?: LLM_LOCAL_URL, model)`. The only override seam,
`createChatClientWithConfig()`, picks the provider **implicitly from whether an
apiKey is non-empty** — so provider cannot be chosen independently of
credentials, and it only knows Ollama-vs-OpenAI. This copy-paste is the routing
model, unfactored, living in application code. This ADR lifts it into the
framework.

## Decision

Route by **named LLM profiles**. A caller asks for a profile by key; the
framework resolves the key to a concrete, immutable client configuration and
hands back a ready `AsyncChatLLMInterface`. Profiles are **defined in the env
catalog as defaults and overridable per-project via settings**. Provider is an
**explicit dimension** of a profile, decoupled from credentials. The model is
**task-oriented now** and **reserves a worker/node placement dimension** as a
declared extension point for the daemon-cluster stage.

### 1. The profile is the unit of routing

A profile is a named, fully-resolved recipe for one LLM conversation role:

```
LlmProfile {
  key:        string        // 'chat.bot', 'chat.moderation', 'chat.analyzer'
  provider:   LlmProvider   // enum: LOCAL | EXTERNAL — explicit, not derived
  url:        string        // endpoint base
  model:      string
  apiKey:     ?string       // null for keyless local; required by EXTERNAL
  timeoutSec: float
  // reserved extension point — see §4:
  placement:  ?LlmPlacement // node/worker capability hint; null today
}
```

`key` is a stable string owned by the caller's domain (the chat demo owns
`chat.*`). The framework does not enumerate application profile keys; it
enumerates the **fields** a profile resolves to. Provider is an explicit enum on
the profile — resolving `{provider: EXTERNAL, apiKey: null}` is a configuration
error surfaced as `LLMConfigurationException`, never a silent fallback to local.

### 2. Resolution: env defaults, settings override

A profile is resolved by merging two layers, most-specific-wins:

1. **Env catalog default.** `EnvCatalogStub` gains a `LLM_PROFILES` structure:
   for each profile key, the default `{provider, url, model, timeout}` and which
   apiKey env var it draws from. This keeps framework config declarative and
   test-friendly (a test env pins profiles without a DB).
2. **Settings override.** An admin settings row may override any field of a
   profile at runtime, matching how the chat demo already pulls
   `CHAT_*_PROVIDER` from settings today. Absent an override, the env default
   stands.

Resolution is pure and deterministic given (env, settings); it performs no I/O
to the LLM. Credentials still come only from env/secret, never from a settings
row written by a browser user — a settings override may switch *which* keyed
credential a profile uses, but not inline a secret value.

### 3. The router API — one seam above `ClientFactory`

A single framework entry point replaces the three copied branches:

```php
interface LlmRouter {
    /** Resolve a profile key to an immutable, fully-configured profile. */
    public function resolve(string $profileKey): LlmProfile;

    /** Build (or reuse) an async chat client for a resolved profile. */
    public function chatClientFor(string $profileKey): AsyncChatLLMInterface;
}
```

- Agents call `chatClientFor('chat.bot')` in their constructor instead of
  hand-rolling `createChatClient()` vs `createChatClientWithConfig()`.
- `ClientFactory` stays the low-level constructor of a provider from an explicit
  `{provider, url, model, apiKey, timeout}` tuple; the router is the policy layer
  that decides that tuple from a key. `LLM_CHAT_PROVIDER`/`LLM_IMAGE_PROVIDER`
  become the resolved default of a built-in profile, not a global branch read
  from three call sites.
- One client instance per profile per owner (the current one-provider-per-agent
  reality): a single in-flight request per client is unchanged by this ADR.
  Concurrency across roles comes from distinct profiles, exactly as the chat
  demo runs three agents today.

`createChatClientWithConfig()`'s implicit apiKey-means-external rule is retired;
the router selects provider from the profile's explicit `provider` field.

### 4. Reserved: worker/node placement (daemon-cluster)

The daemon-cluster stage will run heavy LLM workers on capable nodes. This ADR
**does not** implement placement, but reserves the seam so the contract does not
have to be reopened:

- `LlmProfile.placement` is a declared, currently-null field: a hint describing
  what kind of node/worker should serve this profile (e.g. a capability tag such
  as `gpu-local`, or `any`).
- `LlmRouter.chatClientFor()` is the choke point where a future cluster-aware
  resolver can decide to serve the profile in-process or dispatch it to a worker
  on another node, without changing any caller.

Callers depend only on `chatClientFor(key)` returning an
`AsyncChatLLMInterface`. Whether that client talks to a local socket or, later,
to a remote worker is a router concern. Nothing in the profile contract assumes
same-process execution.

## What migrates

The three chat agents move onto profiles. Their per-agent provider branches
collapse to a single `chatClientFor('chat.bot' | 'chat.moderation' |
'chat.analyzer')` call. The `CHAT_*_PROVIDER/URL/MODEL/TIMEOUT` env + settings
keys become the override source for those three profiles' fields — no behaviour
change for the demo, one abstraction instead of three copies. `ModeratorAgent`'s
`APP_ENV==='test'` swap to `TestModerationChatClient` stays a test seam layered
above the router (the router resolves a profile; the test still injects a fake
client), so profiles do not leak test concerns.

## Scope boundary — TS agent-host is out

`framework/agent-host` (the `@anthropic-ai/claude-agent-sdk` +
`@openai/agents` HTTP/WS service on :9309) is **dev-only tooling**: it mounts the
repo read-only for code-guardian review and has zero call path from any PHP
runtime. Per the subscription ruling the Agent SDK is legal only for
development. It is mapped here solely to fix the boundary: **runtime LLM routing
is the PHP `framework/backend/LLM` layer described above; the TS agent-host is
not part of it and Phase-1 leaves do not touch it.**

## Non-goals of this ADR

- **Token streaming.** Both providers hardcode `stream=false`; streaming is a
  separate concern and not introduced by profiles.
- **Multiplexed in-flight requests per client.** One in-flight request per
  client stands; concurrency is per-profile.
- **Cost/usage accounting.** Per-token external cost tracking is HIL-260 and
  builds on the resolved `EXTERNAL` profile, not defined here.
- **Placement implementation.** Reserved (§4), delivered with daemon-cluster.

## Phase-1 leaves this gates

- **HIL-257** — the `LlmRouter` + profile resolution engine (this contract).
- **HIL-258** — runtime bot-agent abstraction consuming `chatClientFor()`.
- **HIL-259** — Ollama local engine hardening behind the `LOCAL` provider.
- **HIL-260** — per-token external API + cost/usage on the `EXTERNAL` provider.
