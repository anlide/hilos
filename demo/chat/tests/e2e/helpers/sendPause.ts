import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// Chat's send pause on the stand. After an accepted send the composer keeps
// Send disabled for the product's rate-limit period; a spec that sends again
// ends that pause by Chat's test-only command instead of waiting it out. Chat
// ages the person's last send on its runtime row, and the browser learns it
// over the socket, so the spec then waits for Send by the control's state.

const END_SEND_PAUSE_COMMAND = 'test:chat:end-send-pause'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

/**
 * End the send pause of a person who has sent a message.
 *
 * The production period does not change: Chat ages the moment of the person's
 * last accepted send, and its own rule accepts the next one. A refusal fails
 * the spec here and now — a person who has sent nothing has no pause to end —
 * rather than later on a Send that never comes back.
 *
 * @param userId The person whose send pause ends.
 * @throws {Error} When the command is refused or answers for another person.
 */
export async function endSendPause(userId: number): Promise<void> {
  const reply = await command(END_SEND_PAUSE_COMMAND, { userId })
  if (
    !reply ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    Object.keys(reply).join() !== 'userId' ||
    reply.userId !== userId
  )
    throw new Error(
      `Invalid ${END_SEND_PAUSE_COMMAND} reply for user ${userId}`,
    )
}
