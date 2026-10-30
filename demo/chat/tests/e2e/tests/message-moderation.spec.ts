import { test, expect } from '@playwright/test'

import {
  openBell,
  unreadBadge,
  clickSubmit,
  typeInto,
  createTestUserJoined,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation.js'

// spec-owner: demo — chat consumes framework moderation verdicts for its messages

// A message rides the real WS action, runtime check and framework moderator;
// ChatAgent applies the verdict before the browser sees publication or a refusal.
// Invalid answers and unavailable calls return the draft without a content notice.
//
// The framework moderator asks the stand gateway's model, which says only what
// a spec dictated (helpers/moderation.ts). Each test coins its own key and puts it
// into the text of its message, so it disturbs no other spec. /test/reset is never
// called here — it would wipe what the other workers dictated.
//
// Recovery waits for the original re-send lockout through the control's state;
// room.spec.ts holds the lockout itself.

test('a rejected message stays out of the room and its author is told', async ({
  page,
}) => {
  // A cold load after binding joins the author's notification group before
  // the verdict raises the notification (see createTestUserJoined).
  await createTestUserJoined(expect, page)

  const key = modelKey()
  const text = `a message the model refuses ${key}`
  await dictateModerationVerdict(key, false, 'spam')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

  // The banner is the settled state: the verdict is applied in one step, and the
  // refusal branch publishes nothing — so the room is judged only after it.
  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Message rejected: it appears to be spam.',
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
  await expect(menu).toContainText('Message rejected: it appears to be spam.')
  await expect(menu.getByTestId(/^hilos-notification-item-/)).toHaveCount(1)
})

test('an unavailable message returns its draft and a corrected retry publishes once', async ({
  page,
}) => {
  await createTestUserJoined(expect, page)

  // No verdict is dictated: the model refuses the call with a 503, which for the
  // product is a model that is not answering.
  const key = modelKey()
  const text = `a message nobody moderates ${key}`

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Moderation is unavailable right now. Your message is still here.',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
  await expect(page.getByTestId('message-input')).toHaveValue(text)

  await openBell(page)
  await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
  await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
  await expect(unreadBadge(page)).toBeHidden()
  await page.getByTestId('hilos-notification-toggle').click()

  const corrected = `a corrected message ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await typeInto(page.getByTestId('message-input'), corrected)
  // Unavailable does not clear the original ten-second submit lockout.
  await expect(page.getByTestId('message-send')).toBeEnabled({
    timeout: 20_000,
  })
  await clickSubmit(expect, page.getByTestId('message-send'))
  await expect(page.getByTestId('moderation-text')).toHaveCount(0)
  await expect(page.getByTestId('message-input')).toHaveValue('')
  const messages = page.getByTestId('event-text').filter({ hasText: key })
  await expect(messages).toHaveCount(1)
  await expect(messages).toHaveText(corrected)
})

test('an undeclared refusal reason returns the draft as unavailable without a content notice', async ({
  page,
}) => {
  await createTestUserJoined(expect, page)

  const key = modelKey()
  const text = `a message blocked without category ${key}`
  await dictateModerationVerdict(key, false, 'blocked')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Moderation is unavailable right now. Your message is still here.',
  )
  await expect(page.getByTestId('moderation-text')).not.toContainText('blocked')
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
  await expect(page.getByTestId('message-input')).toHaveValue(text)

  await openBell(page)
  await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
  await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
  await expect(unreadBadge(page)).toBeHidden()
})
