import { postToGateway } from './gateway'

// The stand's local model (HIL-925). The stand gateway answers the completion
// API the daemon's local model provider calls, and what it answers is exactly
// what a spec dictated here beforehand: the model on the stand says nothing a
// spec did not order, and a call nobody ordered an answer for is refused with a
// 503 — for the product, a model that is not answering.
//
// The model is an interlocutor, not a delivery channel: nothing it is told
// reaches a mailbox, so there is nothing to read back. A spec dictates the
// answer and checks what the product did with it.

/**
 * Dictate the text the model answers with on the next call that mentions a key.
 *
 * The key is a unique string the spec itself puts into the conversation — the
 * text of a message, a new name. No field of a model call carries a value the
 * spec knows in advance (the product builds the prompt from its own template),
 * so the gateway looks for the key in the call's prompt as a SUBSTRING; a key
 * that is part of another spec's text would be spent by that spec's call, which
 * is why it has to be unique.
 *
 * One dictation answers exactly one call; dictations for the same key queue up
 * and play out in the order they were made, so "first a refusal, then a
 * permission" is two calls of this helper.
 *
 * Do not combine a dictated answer with a dictated status on the same key
 * (`dictateGatewayBehavior` with `status`): a call refused with a status never
 * reaches the model, so the dictated text would stay behind and answer the next
 * call instead. A delay, a cut or a hold combine freely.
 *
 * @param key The string the call's prompt has to contain.
 * @param response The raw text the model answers with; an empty string is a legitimate answer.
 */
export async function dictateModelAnswer(
  key: string,
  response: string,
): Promise<void> {
  await postToGateway('/model/test/answer', { key, response })
}
