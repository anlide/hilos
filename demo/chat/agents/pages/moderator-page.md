# ModeratorPage and Other Pages

## ModeratorPage

**Page constant:** `PageConstants::MODERATOR` | **Agent:** `ModeratorAgent`

Moderation console page. Shows pending moderation items, allows manual override.

`onSubscribe`: sends current moderation queue state.

Actions: ban user, allow/reject moderation, manage moderator prompt pieces.

---

## ProfilePage

**Page constant:** `PageConstants::PROFILE` | **Agent:** `ChatAgent`

User's own profile view. Shows personal settings.
`onSubscribe`: sends current user entity + online connections.

---

## UserPage (chat-level)

**Page constant:** `PageConstants::USER` | **Agent:** `ChatAgent`

View another user's profile (admin usage).
`onSubscribe`: sends target user entity.

---

## Admin pages

All routed to `ChatAgent`:

| Page constant | Route | Content |
|---|---|---|
| `ADMIN` | `/admin` | Admin dashboard |
| `ADMIN_USERS` | `/admin/users` | User management table |
| `ADMIN_MODERATOR` | `/admin/moderator` | Moderator prompt pieces table |
| `ADMIN_BOTS` | `/admin/bots` | Bot management table |

Each admin page `onSubscribe` sends the relevant `Table` data.

---

## Hilos system pages

| Page constant | Agent | Route |
|---|---|---|
| `HILOS_DASHBOARD` | `HILOS_INDEX` | `/hilos` |
| `HILOS_SETTINGS` | `HILOS_INDEX` | `/hilos/settings` |
| `HILOS_I18N` | `HILOS_INDEX` | `/hilos/i18n` |
| `HILOS_GUARDIAN` | `HILOS_GUARDIAN` | `/hilos/guardian` |
| `HILOS_ANALYTICS` | `HILOS_ANALYTICS` | `/hilos/analytics` |
| `HILOS_LOGS` | `HILOS_LOGS` | `/hilos/logs` |
| `HILOS_USER` | `HILOS_INDEX` | `/hilos/users/:id` |
| `HILOS_USERS` | `HILOS_INDEX` | `/hilos/users` |
| `SIL_REQUESTS` | `HILOS_INDEX` | `/hilos/sil/requests` |
| `SIL_USER_HISTORY` | `HILOS_INDEX` | `/hilos/sil/:id` |
