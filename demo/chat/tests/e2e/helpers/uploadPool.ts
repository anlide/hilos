import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { createUploadPoolDriver } from '../../../../../framework/frontend/scripts/uploadPool.mjs'

/** Bind the framework's test driver to chat's existing command channel. */
export function chatUploadPool(
  record: (event: Record<string, unknown>) => void,
) {
  return createUploadPoolDriver(
    createCommandChannel({
      host: process.env.COMMAND_HOST ?? 'chat-test',
      port: Number(process.env.COMMAND_PORT ?? 8094),
      timeoutMs: 5_000,
    }),
    record,
  )
}
