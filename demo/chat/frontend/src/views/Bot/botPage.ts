// The bot detail page selectors: the requested bot resolved by reference into
// the profile the page renders, plus its reactive agent runtime status. The
// page entity `bot` (BotPage::buildPagePayload) supplies the reference; profile
// freshness (a rename or a cleared field in the bots admin) comes from the
// `botProfile` list (backend BotProfileBrowserList), whose `bots` slot is
// normalized into the same Bots entity, so `Bots.signal(ref)` sees the change.
// The status rides the reactive `botStatus` data slot, so a join/leave flips
// `online` without re-streaming the profile. The view reads this signal and
// never touches a raw store.
import {
  computedSignal,
  readString,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core/base'

import { scopes } from '../../bootstrap/session.js'
import { Bots } from '../../types/index.js'
import { type BotDetail } from './types/BotDetail.js'

// Wire keys: the bot entity slot (backend BotPage::ENTITY_SLOT) and the
// agent status data slot (backend ChatBrowserData::BOT_STATUS).
const BOT_ENTITY_SLOT = 'bot'
const BOT_STATUS_DATA = 'botStatus'
// The runtime status field and its joined marker, mirroring the backend
// Demo\Chat\Runtime\State\Item\BotAgentStatus.
const STATUS_FIELD = 'status'
const STATUS_JOINED = 'joined'

/** Read a page data slot as an inline record, or undefined. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

const botRef = scopes.pageDataSignal(BOT_ENTITY_SLOT)
const botStatusData = scopes.pageDataSignal(BOT_STATUS_DATA)

/**
 * The requested bot's detail, or undefined until its profile entity lands: the
 * referenced bot resolved reactively, with the agent's runtime status folded in
 * so a join/leave flips `online` without re-streaming the profile.
 */
export const botDetail: ReadonlySignal<BotDetail | undefined> = computedSignal(
  () => {
    const ref = botRef.get() as EntityRef | undefined
    const bot = ref ? Bots.signal(ref).get() : undefined
    if (!bot) {
      return undefined
    }
    const status = recordSlot(botStatusData.get())

    return {
      name: bot.name,
      description: bot.description ?? '',
      style: bot.style ?? '',
      topics: bot.topics ?? '',
      personality: bot.personality ?? '',
      active: bot.active,
      online: status
        ? readString(status, STATUS_FIELD) === STATUS_JOINED
        : false,
    }
  },
)
