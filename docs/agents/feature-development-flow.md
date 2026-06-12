# AI Feature Development Flow

Read this when starting a feature with AI assistance, or when changing how the
elicitation/planning agents are wired.

This is a **working design** — the orchestration below is agreed; the items
under "Open forks" are not yet decided, and the planner subagent and scaffold
skill described here are **not built yet**. This doc is the checkpoint of the
agreed shape, to be continued.

## Core principle: the data structure decides everything

In Hilos a feature almost always decomposes into a fixed set of primitives
(page, agent, signal, table, ORM model, RT state, FE wiring). Getting those
right is mechanical **once the data structure is right**. The decisive factor is
the data structure / backend truth source — the foundation every other primitive
decorates. If it is wrong, the rest is wasted.

Agents produce over-generic ("universal") data structures when the request is
underspecified — that is the user's missing detail, not the agent's fault. So
the flow's whole job is to pin the data structure down with the right amount of
questioning, and only then decorate it.

The dependency ladder every plan is reordered onto:

```
1. Backend truth source / data   (ORM model, RT state, owner agent)   <- the foundation
2. Contract                      (signal / DTO / route / payload)     <- approval gate
3. FE infrastructure             (subscription, ingest, route)
4. Render                        (page actually on screen)
5. Heavy primitives LAST         (tables)
```

Jumping to a heavy primitive (tables) before the foundation is the known failure
mode — reorder onto this ladder even when the request is phrased from the end.

## Tiers (canonical set of three)

A feature is built at one of three tiers. The tier sets how much is asked before
work starts, how much tech debt is acceptable, and how the output is isolated.

| Tier | Data-structure questions | Tech debt | Isolation | Intent |
|---|---|---|---|---|
| **Spike** | 0 — pick a generic shape, log the assumptions | explosive, accepted | git worktree, so it is trivially discardable | throwaway; review the idea, then delete |
| **Experimental** | only load-bearing forks: durable vs RT, owner agent, key, cardinality | controlled | normal branch | can be matured later to acceptable support |
| **Production** | every Hilos data-placement fork + access gradient, lifecycle, item-vs-action | zero | branch + structure approved | thought through fully before building |

The load-bearing questions of the Experimental tier are exactly the **costly,
hard-to-reverse** forks. Cosmetic questions ("what other fields") belong to
Production only.

The data-elicitation questionnaire is **one thing whose depth equals the tier** —
not an on/off switch and not a separate questionnaire per tier.

### Tier selection

- **Inference first** from the request phrasing ("sketch / quick / just to see"
  -> Spike; "think it through / production / long-lived" -> Production; a large
  vague ask -> Spike by default).
- **Explicit override** in the prompt wins.
- **Ask only when genuinely ambiguous.** Default to Experimental when unsure.
- The questionnaire must **not** pop up automatically — questioning is deliberate
  (Production, or a real ambiguity), never reflexive.

### Promotion (one-way ratchet)

Tiers are a ratchet: a Spike you liked can be **promoted** to Experimental, then
Production. Promotion re-runs the flow at the higher tier (asking the forks that
tier requires) on top of what the lower tier found. This is what lets "one-prompt,
no questions" and "thorough, zero debt" coexist — same knob, three positions,
plus a path upward.

## Orchestration: flat, conductor + leaves

- The **main loop is the conductor** ("верховный"): it owns the user channel
  (the only place that can ask questions), tier selection, fan-out to subagents,
  and final assembly + commit.
- **Subagents are leaves.** They run headless, read heavily, return a result.
  A subagent has no channel to the user, so it cannot interview — interactivity
  lives only in the main loop.
- **No sub-subagents.** Do not architect on nested spawning; it is unreliable and
  the Workflow engine forbids nesting beyond one level. If a leaf needs heavy
  parallel reading, the conductor initiates that fan-out, not the leaf.

This is why an "interview subagent" cannot exist: a Spike (zero questions) is
subagent-friendly precisely because no user channel is needed; a Production
interview is main-loop-mandatory because it needs one.

## Production: iterative ping-pong planner

For the Production tier the planner is a **persistent subagent** that the
conductor keeps re-engaging via `SendMessage` (a fresh `Agent` call would lose
context; `SendMessage` continues the same agent with its context intact). The
planner accumulates understanding of the data structure across rounds; the
conductor only shuttles questions and answers between the user and the planner.

```
planner = spawn(...)                       # lives across all rounds
loop:
    {draft, openQuestions[], unresolvedRisks[], readyToBuild} = planner round
    if openQuestions empty or readyToBuild:  break
    answers = conductor asks the USER (AskUserQuestion)
    SendMessage(planner, answers)           # same agent, context preserved
-> plan ready
```

- Each round returns the structured shape above so the loop has a defined
  convergence: stop when `openQuestions` is empty, `readyToBuild` is true, the
  user says stop, or a round cap is hit.
- Approving the structure happens implicitly: the user steers it by answering the
  per-round questions, so convergence *is* the approval.
- Spike is the same planner with **zero rounds**: one headless pass, no questions,
  build.

## Entities (count does not grow)

- **Conductor / main loop** — tier, questions to the user, fan-out, assembly, commit.
- **Planner subagent** — Production: iterative convergence on structure + plan;
  Spike: one headless pass. (Not built yet.)
- **Scaffold skill** (`scaffold-hilos-project`) — separate, over
  `docs/new-project/README.md`, step-by-step and visible in the main context
  (includes the worker-pool lesson: counts live in compose env, `MIN_MONOPOLISTIC >= 2`).
  (Not built yet.)

## Open forks (undecided)

1. **Production phase shape** — fuse elicitation and architecture into one
   persistent iterative planner (current lean), or keep two separate phases with
   an explicit structure-approval step between them.
2. **Question authorship** — the planner hands a final question list that the
   conductor only relays, or the conductor may repackage/filter the planner's
   questions before showing them (e.g. drop ones already answered by the code).
