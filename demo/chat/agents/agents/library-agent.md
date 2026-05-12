# LibraryAgent

**Type:** `AgentType::LIBRARY` (`'library'`) | **Worker:** Monopolistic

Owns admin-managed chat library data: bot profiles and moderator prompt pieces.

## Responsibilities

- Register `ChatDbContext::bots` as the DB truth source.
- Register `ChatDbContext::moderatorPromptPieces` as the DB truth source.
- Serve `ADMIN_BOTS` subscriptions and `BOT_CREATE` / `BOT_UPDATE` / `BOT_DELETE` actions through `AdminBotsPage`.
- Serve `ADMIN_MODERATOR` subscriptions and moderator prompt piece CRUD actions through `AdminModeratorPage`.
- Start a `BotAgent:botId` after creating or reactivating an active bot.

`LibraryAgent` does not run LLM work and does not write chat events, users, or runtime connection state.
