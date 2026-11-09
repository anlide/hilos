# LibraryAgent

**Type:** `AgentType::LIBRARY` (`'library'`) | **Worker:** Monopolistic

Owns admin-managed chat library data: bot profiles.

## Responsibilities

- Register `ChatDbContext::bots` as the DB truth source.
- Serve `ADMIN_BOTS` subscriptions and `BOT_CREATE` / `BOT_UPDATE` / `BOT_DELETE` actions through `AdminBotsPage`.
- Start a `BotAgent:botId` after creating or reactivating an active bot.
- Answer the test-only `test:chat:bot-answer-now` command: an active bot's agent gets `BOT_ANSWER_NOW` and the command is
  accepted at once; a stopped or unknown bot is refused, because the signal would start an agent nothing stops afterwards.
- Answer `test:chat:bot-extras-create` and `test:chat:bot-extras-delete` on the test command channel: create 1–20 inactive bots
  named `ZZ 1 extra <marker> <n>`, or remove only inactive bots under that marker. Cleanup is idempotent; both commands refuse
  outside non-production environments.

`LibraryAgent` does not run LLM work and does not write chat events, users, or runtime connection state.
