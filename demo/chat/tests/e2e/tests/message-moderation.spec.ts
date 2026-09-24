import { test, expect } from '@playwright/test'

import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation'
import { openBell, signUpJoined, unreadBadge } from '../helpers/notifications'
import { clickSubmit, signUp, typeInto } from '../helpers/session'

// Message moderation on a real call to the stand's model (HIL-927): a refusal, and
// a model that does not answer. Until this leaf the moderator swapped in an
// in-process client on the stand that always allowed, so neither outcome had ever
// been seen by a run — only the path on which moderation never fired.
//
// The moderator agent asks the stand gateway's model, and the model says only what
// a spec dictated (helpers/moderation.ts). Each test coins its own key and puts it
// into the text of its message, so it disturbs no other spec. /test/reset is never
// called here — it would wipe what the other workers dictated.
//
// The Send button is not asserted on: a send starts the re-send lockout, and
// waiting it out would test the lockout, not moderation (connection.spec.ts holds
// the lockout).

test('a rejected message stays out of the room and its author is told', async ({
  page,
}) => {
  // A cold load after sign-up, so the socket has joined the author's notification
  // group before the verdict raises the notification (see signUpJoined).
  await signUpJoined(page)

  const key = modelKey()
  const text = `a message the model refuses ${key}`
  await dictateModerationVerdict(key, false, 'spam')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))

  // The banner is the settled state: the verdict is applied in one step, and the
  // refusal branch publishes nothing — so the room is judged only after it.
  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Message rejected: spam',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)

  // The text comes back into the composer for the author to edit.
  await expect(page.getByTestId('message-input')).toHaveValue(text)

  // The product emit never hands the id out, so the row is found by its own title.
  await expect(unreadBadge(page)).toHaveText(/^1\b/)
  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText('Your message was not published')
  await expect(menu).toContainText('Moderation rejected it: spam')
})

test('a message nobody dictated a verdict for comes back as moderation unavailable', async ({
  page,
}) => {
  await signUp(page)

  // No verdict is dictated: the model refuses the call with a 503, which for the
  // product is a model that is not answering.
  const key = modelKey()
  const text = `a message nobody moderates ${key}`

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Moderation unavailable: service_unavailable',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
  await expect(page.getByTestId('message-input')).toHaveValue(text)
})
