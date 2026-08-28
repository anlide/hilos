// The group-join frames (`group_response` / `subscription_group_error`): the two
// answers a `group_subscribe` gets, and there is no third — a join that reaches
// nobody is refused by the master rather than dropped, and a group class that
// carries no content still answers with an empty frame. Framework-owned like
// page_response, they ride the project-signal seam (registered in
// GROUP_SIGNAL_SCHEMAS) rather than a parse-boundary kind of their own.
//
// The response payload is deliberately unvalidated here: the frame type is common
// to every group, so what `payload` holds is whatever THAT group builds, and only
// the binder listening for its own group knows the shape. Each binder validates
// its own — see bindNotificationsScope.
import { z } from 'zod'
import {
  SIGNAL_TYPE_GROUP_RESPONSE,
  SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR,
} from './constants.js'

/**
 * The group-join answer (PHP `GroupResponseSignalData`): the FULL group name the
 * connection joined — which for a group the server addresses itself is not the
 * name the client sent — plus whatever that group answers with. The payload key
 * is absent when the group carries no content, which is a legitimate outcome.
 */
export const groupResponseSchema = z.looseObject({
  group: z.string().min(1),
  payload: z.record(z.string(), z.unknown()).optional(),
})

export type GroupResponse = z.infer<typeof groupResponseSchema>

/**
 * The group-join refusal (PHP `GroupSubscriptionErrorSignalData`): the group name
 * as the CLIENT sent it — a refused join never reached the full name the server
 * would have built — plus the HTTP status, a machine-readable code, and a
 * human-readable message.
 */
export const groupSubscriptionErrorSchema = z.looseObject({
  group: z.string().min(1),
  httpCode: z.number().int(),
  errorCode: z.string(),
  message: z.string(),
})

export type GroupSubscriptionError = z.infer<
  typeof groupSubscriptionErrorSchema
>

/**
 * The group-join schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the envelope of both answers. {@link createHilosConnection}
 * merges them in, so a project never restates them.
 */
export const GROUP_SIGNAL_SCHEMAS = {
  [SIGNAL_TYPE_GROUP_RESPONSE]: groupResponseSchema,
  [SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR]: groupSubscriptionErrorSchema,
}
