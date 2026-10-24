import { dictateModelAnswer } from '../../../../../framework/frontend/scripts/standModel.mjs'

// Moderation on the chat stand. The framework moderator checks profile names;
// chat's moderator still checks messages and pictures. Both ask the stand's
// local model with a real call and read the same {allow, reason} JSON shape.
// The gateway hands back raw text; the product's parser decides its meaning.

/**
 * Dictate the verdict the model gives on the next moderated action that mentions a key.
 *
 * Profile names use the framework's strict parser and PROFILE_NAME kind.
 * Messages and pictures still use `ModerationDecision::fromModelOutput()`
 * (demo/chat/backend/Agents/DTO/ModerationDecision.php).
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
