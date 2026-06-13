# Frontend Page Module Structure

How the files of one page are grouped, named, and where a page's types live.
Read this before creating, moving, or reviewing the files of a page in a
frontend project (the Vue, React, and Angular demos, and any real consumer).

This is a file-layout and naming rule, not a behavior spec — it composes with
[data-model.md](data-model.md) (where the entity store and scopes live) and
[multiframework-core.md](multiframework-core.md) (the agnostic core and the
per-framework view adapters).

## One folder per page

Every page is a folder under the project's `views/` directory (Angular keeps
its `views/` under `src/app/`). The folder name **is the page key** — the same
identifier the page subscribes under (`pages/keys.ts`, mirroring the backend
`PageConstants` — see [page-registry.md](page-registry.md)) — cased by the
engine idiom:

- **Vue / React** — `PascalCase` folder: `views/Main/`, `views/Dashboard/`.
- **Angular** — `kebab-case` folder: `src/app/views/main/`, `.../dashboard/`.

The page key is the single source of the folder name: a page keyed `main` lives
in `Main/` (Vue/React) or `main/` (Angular), never `Home/` or `chat-main/`.

## Files in a page module

Each file's name carries the page-key prefix and a role suffix, cased by the
engine idiom (`PascalCase` view file in Vue/React, `kebab-case` everywhere in
Angular). Using the `main` page as the example:

| Role | Vue | React | Angular |
|---|---|---|---|
| View (markup) | `Main.vue` | `Main.tsx` | `main.ts` |
| Selectors (display data) | `mainPage.ts` | `mainPage.ts` | `main-page.ts` |
| Actions (outbound + their errors) | `mainActions.ts` | `mainActions.ts` | `main-actions.ts` |
| Error handling (optional) | `mainError.ts` | `mainError.ts` | `main-error.ts` |
| Page-local types | `types/` | `types/` | `types/` |

The **view file is the page name with no suffix** — `Main.vue`, not
`MainView.vue`; the folder already says it is a view. In Angular the class and
selector follow suit and drop the legacy suffix: `class Main`, selector
`app-main` (the v20+ style guide already drops the `.component` suffix).

Do **not** add a barrel `index.ts` inside a page folder — import each file by
its own path. A page module is small and self-contained; a barrel only adds an
indirection to maintain.

## File responsibilities

- **View** (`Main.vue` / `Main.tsx` / `main.ts`) — the markup. It reads the
  page's signals from `…Page.ts` and calls into `…Actions.ts`; it never touches
  a raw store or the connection directly.
- **`…Page.ts` — selectors (read only).** Resolves the page payload's lists and
  data slots into the view-models the page renders. Pure projection: no writes,
  no actions.
- **`…Actions.ts` — outbound actions and their errors.** The client→server
  actions the view fires (through the core `sendAction` primitive) plus the
  reactive read of each action's framework `action_error`. Inbound server
  signals are **not** handled here — they are ingested centrally by the SDK
  page-scope binder (`bindPageScope`, see [page-registry.md](page-registry.md)),
  not per page.
- **`…Error.ts` — optional.** Add it only when a page grows error-handling
  logic that is **not** tied to a single action (aggregation, classification, a
  page-level banner). A single action's `action_error` stays in `…Actions.ts`,
  next to the action whose contract it belongs to. Do not create an empty
  `…Error.ts` ahead of a real need.
- **`types/` — page-local types.** See the next section.

## Type placement: domain vs page-local

- **`src/types/`** holds **domain entities** (the normalized wire entities that
  resolve through the entity store — e.g. `User`, `Bot`, `Event`) and **shared
  value types** used across more than one page (e.g. `Presence`). These are not
  owned by any one page.
- **`views/<Page>/types/`** holds **types specific to that one page**. Group the
  list-row view-models (an `…Item` per list row, e.g. `ParticipantItem`,
  `BotItem`, `EventItem`) under a `types/lists/` subfolder — they are a kind of
  their own. Keep other page-local types — a page-local value type such as the
  composer's `SelfConnection` — directly in `types/`.

The test is ownership, not shape: if a type is meaningful only to one page, it
lives in that page's `types/`; if it is a domain entity or is shared across
pages, it lives in `src/types/`. A page-local type that later gets reused by a
second page graduates to `src/types/`.

## Violations

- A page's files scattered in a flat `src/` (`mainPage.ts`, `mainActions.ts`,
  `MainView.vue` side by side at the root) instead of a `views/<Page>/` folder.
- A view file named `…View` (`MainView.vue`) inside a `Main/` folder — the
  suffix duplicates the folder.
- A page-specific view-model (`…Item`) or page-local value type left in
  `src/types/` instead of the page's `types/`.
- A domain entity or a genuinely shared value type pushed down into one page's
  `types/`, forcing other pages to reach across into it.
- A list-row `…Item` view-model left directly in the page `types/` instead of
  its `types/lists/` subfolder.
- A barrel `index.ts` inside a page folder.
- A page folder named anything other than the page key.

## Example — the chat main page (Vue)

```
src/
  types/                     domain entities + shared value types
    User.ts  Bot.ts  Event.ts  Presence.ts  index.ts
  views/
    Main/                    folder name = page key "main"
      Main.vue               view (markup; reads the signals below)
      mainPage.ts            selectors: participants / bots / events / selfConnection
      mainActions.ts         sendChatMessage + its messageError
      types/                 page-local types
        SelfConnection.ts    page-local value type
        lists/               list-row view-models
          ParticipantItem.ts  BotItem.ts  EventItem.ts
    Dashboard/
      Dashboard.vue
```

React mirrors this with `Main.tsx`; Angular mirrors it as
`src/app/views/main/main.ts` (class `Main`, selector `app-main`),
`main-page.ts`, `main-actions.ts`.
