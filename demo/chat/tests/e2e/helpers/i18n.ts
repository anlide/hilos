import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
// The i18n library answers after its database transaction, not from the master loop.
const REPLY_TIMEOUT_MS = 15_000
const LANGUAGE_ON_COMMAND = 'test:i18n:language:on'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Make one built-in language available for a browser test through its owner.
 *
 * @param code Two-letter language code from the built-in catalog.
 */
export async function switchLanguageOn(code: string): Promise<void> {
  await sendCommand(LANGUAGE_ON_COMMAND, { code })
}
