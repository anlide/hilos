import { test as base, expect } from '../test.js'

import {
  cleanupFixtures,
  type TestCleanup,
  openBell,
  unreadBadge,
  clickSubmit,
  typeInto,
  createTestUserJoined,
  expectModerationKindHead,
  expectModerationKindOverview,
  gotoPage,
  grantAdminToSelf,
  shownByTestId,
  type ModerationKindDeclaration,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  dictateModerationVerdict,
  forgetModelKey,
  modelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { endSendPause } from '../helpers/sendPause.js'

// spec-owner: demo — chat consumes framework moderation verdicts for its messages

// A message rides the real WS action, runtime check and framework moderator;
// ChatAgent applies the verdict before the browser sees publication or a refusal.
// Invalid answers and unavailable calls return the draft without a content notice.
//
// The framework moderator asks the stand gateway's model, which says only what
// a spec dictated (framework/frontend/scripts/standModel.mjs). Each test coins
// its own key and puts it into the text of its message, so it disturbs no other
// spec. /test/reset is never called here — it would wipe what the other workers
// dictated.
//
// Recovery ends the original re-send lockout by command and then waits for the
// control's state; room.spec.ts holds the lockout itself.
//
// The last test proves that the two kinds the chat declares — its messages and
// their pictures — are listed in the framework's Moderation section as the
// project's own; the section itself is walked by the Vue kit's moderation.spec.ts
// in roblox-game-server.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// The two kinds the chat declares on top of the framework's.
const CHAT_MESSAGE: ModerationKindDeclaration = {
  kind: 'chat_message',
  title: 'Chat message',
  owner: 'Project',
  modelLabel: 'Text model',
  model: 'qwen2.5:0.5b',
  profile: 'hilos.moderation.text',
  reasons: ['insult', 'threat', 'hate_speech', 'sexual', 'spam'],
  defaultRules: [
    'Default policy: allow benign messages.',
    'Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
    'If uncertain, return allow=true.',
  ],
}
const CHAT_MESSAGE_PICTURE: ModerationKindDeclaration = {
  kind: 'chat_message_picture',
  title: 'Chat message picture',
  owner: 'Project',
  modelLabel: 'Vision model',
  model: 'qwen2.5vl:3b',
  profile: 'hilos.moderation.image',
  reasons: ['nudity', 'violence', 'hate'],
  defaultRules: [
    'Default policy: allow ordinary pictures, screenshots and drawings.',
    'Block only nudity or sexual content, graphic violence, and hate symbols.',
    'If uncertain, return allow=true.',
  ],
}
const CHAT_KINDS = [CHAT_MESSAGE, CHAT_MESSAGE_PICTURE]

test('a rejected message stays out of the room and its author is told', async ({
  page,
  cleanup,
}) => {
  // A cold load after binding joins the author's notification group before
  // the verdict raises the notification (see createTestUserJoined).
  await createTestUserJoined(expect, page)

  const key = modelKey()
  cleanup.defer(() => forgetModelKey(key))
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

test('chat.text-unavailable', async ({ page, cleanup }) => {
  const { userId } = await createTestUserJoined(expect, page)

  await test.step('An unanswered moderation returns the draft, then a corrected retry publishes once and clears the banner', async () => {
    // No verdict is dictated: the model refuses the call with a 503, which for the
    // product is a model that is not answering.
    const key = modelKey()
    cleanup.defer(() => forgetModelKey(key))
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
    // Unavailable does not clear the original ten-second submit lockout; a
    // command ends it.
    await endSendPause(userId)
    await expect(page.getByTestId('message-send')).toBeEnabled()
    await clickSubmit(expect, page.getByTestId('message-send'))
    await expect(page.getByTestId('message-input')).toHaveValue('')
    const messages = page.getByTestId('event-text').filter({ hasText: key })
    await expect(messages).toHaveCount(1)
    await expect(messages).toHaveText(corrected)
    await expect(page.getByTestId('moderation-text')).toHaveCount(0)
  })

  await test.step('A new message with an undeclared refusal shows a new unavailable banner without a content notice', async () => {
    await endSendPause(userId)
    const key = modelKey()
    cleanup.defer(() => forgetModelKey(key))
    const text = `a message blocked without category ${key}`
    await dictateModerationVerdict(key, false, 'blocked')

    await typeInto(page.getByTestId('message-input'), text)
    await clickSubmit(expect, page.getByTestId('message-send'))

    await expect(page.getByTestId('moderation-text')).toHaveText(
      'Moderation is unavailable right now. Your message is still here.',
    )
    await expect(page.getByTestId('moderation-text')).not.toContainText(
      'blocked',
    )
    await expect(
      page.getByTestId('event-text').filter({ hasText: key }),
    ).toHaveCount(0)
    await expect(page.getByTestId('message-input')).toHaveValue(text)

    await openBell(page)
    await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
    await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
    await expect(unreadBadge(page)).toBeHidden()
  })
})

test("the Moderation section lists the chat's two message kinds as its own", async ({
  page,
}) => {
  // Proves: the kinds the chat declares reach the framework's section from the
  // server — listed beside the framework's and opened on their own Overview
  // with the model, profile and reasons of their declaration.
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/moderation')
  const kinds = page.getByTestId('hilos-moderation-kinds-table')
  for (const { kind } of CHAT_KINDS) {
    await expect(
      shownByTestId(kinds, `hilos-moderation-row-${kind}`),
    ).toBeVisible()
  }

  for (const declared of CHAT_KINDS) {
    await test.step(`Overview of ${declared.kind} reads its declaration`, async () => {
      await gotoPage(expect, page, `/hilos/moderation/kind/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)
    })
  }
})
