import { dictateModelAnswer } from '../../../../../framework/frontend/scripts/standModel.mjs'

// Moderation on the chat stand. Profile names, messages and pictures use the
// framework moderator and its strict {allow, reason} parser. The stand's local
// model answers a real call with raw text; the framework validates its meaning.

/**
 * Dictate the verdict the model gives on the next moderated action that mentions a key.
 *
 * Names use PROFILE_NAME, messages use chat_message (TEXT_WITH_FILES metadata
 * only), and pictures use chat_message_picture (IMAGE, one JPEG copy per call).
 * Approval requires reason `ok`; a refusal requires one of the kind's declared
 * reasons. An unknown reason makes the check unavailable, never rejected.
 *
 * EVERY moderated action needs a verdict — sending a message, and
 * renaming oneself from the profile. A message with pictures calls the model
 * once more for each picture. With none dictated the model refuses the call
 * with a 503: names show "Names cannot be checked right now.", while chat
 * reports `service_unavailable` as "Moderation is unavailable right now".
 *
 * The key is a `modelKey()` the spec puts into the text of the message, into
 * the new name, or into a picture's file name: the moderator's prompt carries
 * that text verbatim, and a picture prompt carries the file name, which is
 * where the gateway finds the key. Dictations for one key play in order — the
 * text, then the pictures. Dictate BEFORE the action that calls the model.
 *
 * @param key The string the moderated text or file name contains.
 * @param allow Whether the model approves the check.
 * @param reason The model's raw reason; valid refusals become public product sentences.
 */
export async function dictateModerationVerdict(
  key: string,
  allow: boolean,
  reason: string,
): Promise<void> {
  await dictateModelAnswer(key, JSON.stringify({ allow, reason }))
}
