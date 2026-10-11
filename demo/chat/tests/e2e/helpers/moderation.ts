import { dictateModelAnswer } from '../../../../../framework/frontend/scripts/standModel.mjs'

// Chat moderation on the stand (HIL-927). The moderator agent asks the stand's
// local model with a real call, so every verdict a spec sees is one a spec
// dictated. The shape of a verdict lives here and not in the gateway: the gateway
// hands back raw text and belongs to no demo, while the JSON the moderator parses
// is the chat's own knowledge.

/**
 * Dictate the verdict the model gives on the next moderated action that mentions a key.
 *
 * The text is what `ModerationDecision::fromModelOutput()` parses
 * (demo/chat/backend/Agents/DTO/ModerationDecision.php): a JSON object with the
 * keys `allow` and `reason`.
 *
 * EVERY moderated action needs a verdict — sending a message, and
 * renaming oneself from the profile. A message with pictures calls the model
 * once more for each picture. With none dictated the model refuses the call
 * with a 503, the moderator reports `service_unavailable`, and the author
 * reads "Moderation is unavailable right now" instead of the outcome the spec
 * was written for.
 *
 * The key is a `modelKey()` the spec puts into the text of the message, into
 * the new name, or into a picture's file name: the moderator's prompt carries
 * that text verbatim, and a picture prompt carries the file name, which is
 * where the gateway finds the key. Dictations for one key play in order — the
 * text, then the pictures. Dictate BEFORE the action that calls the model.
 *
 * @param key The string the moderated text or file name contains.
 * @param allow Whether the model lets the text through.
 * @param reason The reason the model gives; the product shows it to the author on a refusal.
 */
export async function dictateModerationVerdict(
  key: string,
  allow: boolean,
  reason: string,
): Promise<void> {
  await dictateModelAnswer(key, JSON.stringify({ allow, reason }))
}
