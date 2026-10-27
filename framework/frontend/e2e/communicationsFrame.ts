import { typeInto } from './input.js'
import type { Expect, Locator, Page } from '@playwright/test'

import {
  LETTER_FRAME_DATA,
  letterFrameSchema,
  type HilosLetterFrame,
} from '../core/src/admin/communications/hilosCommunicationsChannelFrame.js'
import { communicationsChannelReplies } from './communications.js'
import {
  deliveryObject as object,
  type DeliveryFrameLog,
} from './deliveryFrames.js'

export const COMMUNICATIONS_FRAME_PATH = '/hilos/communications/email/frame'
const FRAME_PAGE = 'hilos_communications_channel_frame'
const FRAME_OWNER = 'hilos_communications_channel'
const SET_ACTION = 'communications_channel_set'
const RESET_ACTION = 'communications_channel_reset'

/** Short valid documents keep keyboard input meaningful without typing a prefab. */
export function communicationsFrameHtml(marker: string): string {
  return `<html lang="[[:lang]]" dir="[[:dir]]"><body><p id="${marker}"></p>[[:content]]</body></html>`
}

/** Parse the actual initial or live answer with the production frame schema. */
export function communicationsFrameReplies(log: DeliveryFrameLog, after = 0) {
  return communicationsChannelReplies(log, FRAME_PAGE, after).map((reply) => ({
    ...reply,
    frame: letterFrameSchema.parse(
      object(reply.payload.data)[LETTER_FRAME_DATA],
    ),
  }))
}

/** One cold answer supplies the summary, catalog identity and preview together. */
export async function expectCommunicationsFrameInitial(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  after = 0,
): Promise<HilosLetterFrame> {
  await expect.poll(() => communicationsFrameReplies(log, after).length).toBe(1)
  const reply = communicationsFrameReplies(log, after)[0]!
  expect(reply.summary).toMatchObject({ channel: 'email', label: 'Email' })
  expect(object(reply.payload.data).pageLabel).toBe('Letter frame')
  expect(object(object(reply.payload.data).pageHeadOwner).page).toBe(
    FRAME_OWNER,
  )
  expect(object(reply.payload.windows)).toEqual({})
  expect(
    log.sent.filter(({ wire }) => {
      const request = object(wire)
      const data = object(request.data)
      return data.page === FRAME_PAGE && 'tableKey' in data
    }),
  ).toEqual([])
  await expectCommunicationsFramePreview(expect, page, reply.frame)
  return reply.frame
}

/** The saved synthetic letter stays inside a sandbox with no permissions. */
export async function expectCommunicationsFramePreview(
  expect: Expect,
  page: Page,
  frame: HilosLetterFrame,
): Promise<void> {
  expect(typeof frame.html).toBe('string')
  expect(typeof frame.previewHtml).toBe('string')
  const preview = page.getByTestId('hilos-channel-frame-preview')
  await expect(preview).toHaveAttribute('title', 'Letter frame preview')
  await expect(preview).toHaveAttribute('sandbox', '')
  await expect(preview).toHaveAttribute('srcdoc', String(frame.previewHtml))
  await expect(preview).toHaveAttribute('srcdoc', /482913/)
  await expect(page.getByTestId('hilos-channel-frame')).toContainText(
    'auth.password_reset',
  )
  await expect(page.getByTestId('hilos-channel-frame-source')).toHaveText(
    { settings: 'Override', project: 'Project', default: 'Default' }[
      frame.source
    ],
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
}

/** Type through the real view binding and leave the field before submitting. */
export async function draftCommunicationsFrame(
  expect: Expect,
  page: Page,
  value: string,
): Promise<void> {
  const html = page.getByTestId('hilos-channel-frame-html')
  await expect(html).toBeVisible()
  await typeInto(html, value)
  await html.press('Tab')
  await expect(html).toHaveValue(value)
}

/** The canonical actionable-state sequence for frame submit controls. */
async function submit(expect: Expect, button: Locator): Promise<void> {
  await button.scrollIntoViewIfNeeded()
  await expect(button).toBeVisible()
  await expect(button).toBeEnabled()
  await button.focus()
  await button.click()
}

/** A real request still belongs to Overview while the Frame page is open. */
export function expectCommunicationsFrameAction(
  expect: Expect,
  log: DeliveryFrameLog,
  after: number,
  action: 'set' | 'reset',
  value?: string,
  refusal?: string,
): void {
  const name = action === 'set' ? SET_ACTION : RESET_ACTION
  const requests = log.sent.slice(after).filter(({ wire }) => {
    const request = object(wire)
    return request.type === 'action' && request.action === name
  })
  expect(requests).toHaveLength(1)
  const sent = requests[0]!
  const request = object(sent.wire)
  expect(request.data).toEqual({
    channel: 'email',
    field: 'frame',
    ...(action === 'set' ? { value } : {}),
  })
  expect(typeof request.requestId).toBe('string')
  // A new document restarts request IDs. Earlier authentication replies on a
  // different socket must not count as acknowledgements of this frame write.
  const replies = log.packets
    .filter((packet) => packet.socket === sent.socket && packet.at >= sent.at)
    .flatMap(({ frames }) =>
      frames
        .map(({ wire }) => object(wire))
        .filter(
          (wire) =>
            wire.requestId === request.requestId &&
            object(wire.data).action === name,
        ),
    )
  expect(replies).toHaveLength(1)
  expect(replies[0]!.type).toBe(refusal ? 'action_error' : 'action_success')
  expect(replies[0]!.outcome).toBe(refusal ? 'fail' : 'success')
  const data = object(replies[0]!.data)
  if (refusal) expect(data.reason).toBe(refusal)
  else
    expect(data.message).toBe(
      action === 'set' ? 'Letter frame saved.' : 'Letter frame override reset.',
    )
}

/** Settle the action before reading the saved source and backend preview. */
export async function submitCommunicationsFrame(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  value: string,
  refusal?: string,
): Promise<void> {
  const before = log.sent.length
  await submit(expect, page.getByTestId('hilos-channel-frame-save'))
  if (refusal) {
    await expect(
      page.getByTestId('hilos-channel-frame-save'),
    ).not.toHaveAttribute('aria-busy', 'true')
    await expect(page.getByTestId('hilos-action-error')).toHaveText(refusal)
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      value,
    )
    await expect(page.getByTestId('hilos-channel-frame-save')).toBeEnabled()
  } else {
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveCount(0)
    await expectCommunicationsFrameLive(expect, page, log, value, 'settings')
    await expect(
      page
        .getByTestId('hilos-toast-success')
        .filter({ hasText: 'Letter frame saved.' }),
    ).toContainText('Letter frame saved.')
  }
  expectCommunicationsFrameAction(expect, log, before, 'set', value, refusal)
}

/** Save through the current effective frame's real editor. */
export async function saveCommunicationsFrame(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  value: string,
): Promise<void> {
  await page.getByTestId('hilos-channel-frame-edit').click()
  await expectCommunicationsFrameEditor(expect, page)
  await draftCommunicationsFrame(expect, page, value)
  await submitCommunicationsFrame(expect, page, log, value)
}

/** Await an actual live page publication and its matching DOM projection. */
export async function expectCommunicationsFrameLive(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  html: HilosLetterFrame['html'],
  source: HilosLetterFrame['source'],
): Promise<void> {
  await expect
    .poll(() => communicationsFrameReplies(log).at(-1)?.frame)
    .toMatchObject({ html, source })
  await expectCommunicationsFramePreview(
    expect,
    page,
    communicationsFrameReplies(log).at(-1)!.frame,
  )
}

/** Confirm the reset, settle its reply and return to the original effective frame. */
export async function resetCommunicationsFrame(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  original: HilosLetterFrame,
): Promise<void> {
  const before = log.sent.length
  const saved = await page
    .getByTestId('hilos-channel-frame-preview')
    .getAttribute('srcdoc')
  const reset = page.getByTestId('hilos-channel-frame-reset')
  await expect(reset).toBeEnabled()
  await reset.click()
  const dialog = page.getByTestId('modal')
  await expect(dialog).toHaveAccessibleName('Reset · Letter frame')
  await expect(dialog).toBeFocused()
  await expect(dialog.locator('dl > dt')).toHaveText(['Now', 'Back to'])
  await expect(
    dialog.getByTestId('hilos-channel-frame-reset-now').locator('.badge'),
  ).toHaveText('Override')
  await expect(
    dialog.getByTestId('hilos-channel-frame-reset-back-to'),
  ).toHaveText(
    'the project frame, or the framework frame when the project has none',
  )
  await expect(page.getByTestId('hilos-channel-frame-preview')).toHaveAttribute(
    'srcdoc',
    saved!,
  )
  expect(
    log.sent
      .slice(before)
      .filter(({ wire }) => object(wire).action === RESET_ACTION),
  ).toEqual([])
  await submit(expect, page.getByTestId('hilos-channel-frame-reset-confirm'))
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expectCommunicationsFrameLive(
    expect,
    page,
    log,
    original.html,
    original.source,
  )
  await expect(reset).toBeDisabled()
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Letter frame override reset.' }),
  ).toContainText('Letter frame override reset.')
  expectCommunicationsFrameAction(expect, log, before, 'reset')
}

/** Restore the shared setting through its real reset UI, including failed tests. */
export async function restoreCommunicationsFrame(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  original: HilosLetterFrame,
): Promise<void> {
  const current = communicationsFrameReplies(log).at(-1)?.frame
  expect(current).toBeDefined()
  if (current!.source === 'settings') {
    await resetCommunicationsFrame(expect, page, log, original)
  } else {
    expect(current).toEqual(original)
  }
}

/** An atomic HTML conflict retains both full values and blocks submission. */
export async function expectCommunicationsFrameConflict(
  expect: Expect,
  page: Page,
  mine: string,
  theirs: HilosLetterFrame['html'],
): Promise<void> {
  await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(mine)
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('hilos-channel-frame-notice')).toHaveText(
    'Frame HTML changed elsewhere.',
  )
  await expect(
    page.getByTestId('hilos-channel-frame-incoming').locator('pre'),
  ).toHaveText(String(theirs))
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()
  await expect(page.getByTestId('conflict-accept-mine')).toHaveAccessibleName(
    'Keep mine',
  )
  await expect(page.getByTestId('conflict-accept-theirs')).toHaveAccessibleName(
    'Take theirs',
  )
}

/** Dirty close is a named second layer; cancelling returns to the same draft. */
export async function expectCommunicationsFrameDiscard(
  expect: Expect,
  page: Page,
  draft: string,
): Promise<void> {
  const confirm = page.getByTestId('modal-confirm')
  await expect(confirm).toHaveRole('alertdialog')
  await expect(confirm).toHaveAccessibleName('Discard changes?')
  await expect(confirm).toHaveAttribute('aria-modal', 'true')
  await expect(confirm).toBeFocused()
  await expect(page.getByTestId('modal')).toHaveCount(1)
  await confirm.press('Tab')
  await expect(page.getByTestId('modal-confirm-cancel')).toBeFocused()
  await page.getByTestId('modal-confirm-cancel').press('Shift+Tab')
  await expect(page.getByTestId('modal-confirm-discard')).toBeFocused()
  await page.getByTestId('modal-confirm-cancel').click()
  await expect(confirm).toHaveCount(0)
  await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(draft)
  await expect
    .poll(() =>
      page
        .getByTestId('modal')
        .evaluate((modal) => modal.contains(document.activeElement)),
    )
    .toBe(true)
}

/** The frame editor names its dialog and puts keyboard focus on the HTML. */
export async function expectCommunicationsFrameEditor(
  expect: Expect,
  page: Page,
): Promise<void> {
  const dialog = page.getByTestId('modal')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName('Edit · Letter frame')
  const html = page.getByTestId('hilos-channel-frame-html')
  await expect(html).toHaveAccessibleName('Frame HTML')
  await expect(html).toBeFocused()
  await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()
  const cancel = page.getByTestId('hilos-channel-frame-edit-cancel')
  const close = page.getByTestId('modal-close')
  await cancel.focus()
  await cancel.press('Tab')
  await expect(close).toBeFocused()
  await close.press('Shift+Tab')
  await expect(cancel).toBeFocused()
  await html.focus()
}
