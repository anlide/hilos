import type { Expect, Page } from '@playwright/test'
import {
  channelSummarySchema,
  type HilosChannelSummary,
} from '../core/src/admin/communications/hilosCommunicationsChannel.js'
import {
  deliveryObject as object,
  type DeliveryFrameLog,
} from './deliveryFrames.js'
import { shownByTestId } from './table.js'

const CHANNEL = 'hilos_communications_channel'
const CONFIGURATION = 'hilos_communications_channel_configuration'
const DELIVERIES = 'hilos_communications_deliveries'
const FRAME = 'hilos_communications_channel_frame'

/** The same channel identity and navigation on every channel page. */
export async function expectCommunicationsChannelHead(
  expect: Expect,
  page: Page,
  tab: 'overview' | 'configuration' | 'deliveries' | 'frame',
  channel = 'email',
  label = 'Email',
): Promise<void> {
  const current =
    tab === 'overview'
      ? CHANNEL
      : tab === 'configuration'
        ? CONFIGURATION
        : tab === 'deliveries'
          ? DELIVERIES
          : FRAME
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-head')).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-block')).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(label)
  await expect(page).toHaveTitle(new RegExp(`^${label} · `))
  await expect(page.locator('h1')).toHaveCount(1)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(label)
  const crumbs = page.getByTestId('hilos-breadcrumb').locator('li')
  // React and Angular draw decorative, aria-hidden separators inside each li.
  // Compare the exact ordered labels without counting that decoration as a name.
  await expect
    .poll(() =>
      crumbs.evaluateAll((items) =>
        items.map((item) => {
          const copy = item.cloneNode(true) as HTMLElement
          for (const decoration of copy.querySelectorAll(
            '[aria-hidden="true"]',
          )) {
            decoration.remove()
          }
          return copy.textContent?.replace(/\s+/g, ' ').trim() ?? ''
        }),
      ),
    )
    .toEqual(['Hilos', 'Communications', label])
  await expect(
    page.getByTestId('hilos-breadcrumb').locator('[aria-current="page"]'),
  ).toHaveCount(1)
  await expect(crumbs.last()).toHaveAttribute('aria-current', 'page')
  await expect(page.getByTestId('channel-head-icon')).toBeVisible()
  await expect(page.getByTestId('channel-head-enabled')).toBeVisible()
  await expect(page.getByTestId('channel-head-configured')).toBeVisible()
  await expect(page.getByTestId('channel-head-description')).toContainText(
    `Channel ${channel} · driver`,
  )
  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(1)
  await expect(page.getByTestId(/^hilos-page-tab-/)).toHaveCount(
    channel === 'email' ? 4 : 3,
  )
  await expect(
    page.getByTestId('hilos-page-tabs').locator('[aria-current="page"]'),
  ).toHaveCount(1)
  await expect(page.getByTestId(`hilos-page-tab-${current}`)).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(page.getByTestId(`hilos-page-tab-${CHANNEL}`)).toHaveAttribute(
    'href',
    `/hilos/communications/${channel}`,
  )
  await expect(
    page.getByTestId(`hilos-page-tab-${CONFIGURATION}`),
  ).toHaveAttribute('href', `/hilos/communications/${channel}/configuration`)
  await expect(
    page.getByTestId(`hilos-page-tab-${DELIVERIES}`),
  ).toHaveAttribute('href', `/hilos/communications/${channel}/deliveries`)
  if (channel === 'email') {
    await expect(page.getByTestId(`hilos-page-tab-${FRAME}`)).toHaveAttribute(
      'href',
      '/hilos/communications/email/frame',
    )
  } else {
    await expect(page.getByTestId(`hilos-page-tab-${FRAME}`)).toHaveCount(0)
  }
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
}

/** The shared head uses named link navigation and statuses readable without color. */
export async function expectCommunicationsAccessibility(
  expect: Expect,
  page: Page,
  tab: 'overview' | 'configuration' | 'deliveries' | 'frame',
  enabledName: 'Enabled' | 'Enable Email',
): Promise<void> {
  await expectCommunicationsChannelHead(expect, page, tab)
  const breadcrumb = page.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb).toHaveRole('navigation')
  await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
  const navigation = page.getByTestId('hilos-page-tabs')
  await expect(navigation).toHaveRole('navigation')
  await expect(navigation).toHaveAccessibleName('Channel pages')
  for (const [key, name] of [
    [CHANNEL, 'Overview'],
    [CONFIGURATION, 'Configuration'],
    [DELIVERIES, 'Deliveries'],
    [FRAME, 'Letter frame'],
  ]) {
    const link = page.getByTestId(`hilos-page-tab-${key}`)
    await expect(link).toHaveRole('link')
    await expect(link).toHaveAccessibleName(name!)
  }
  await expect(page.getByTestId('channel-head-icon')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  for (const [id, text] of [
    ['channel-head-enabled', /^(Enabled|Disabled)$/],
    ['channel-head-configured', /^(Configured|\d+ missing)$/],
  ] as const) {
    await expect
      .poll(async () =>
        (await page.getByTestId(id).innerText()).replace(/\s+/g, ' ').trim(),
      )
      .toMatch(text)
  }
  if (tab === 'overview') {
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
    await expect(page.getByTestId('hilos-channel-enabled')).toHaveRole('switch')
    await expect(
      page.getByTestId('hilos-channel-enabled'),
    ).toHaveAccessibleName(enabledName)
    await expect(page.getByTestId('hilos-channel-test')).toHaveRole('button')
    await expect(page.getByTestId('hilos-channel-test')).toHaveAccessibleName(
      'Send test notification',
    )
  }
  // The shell's native bypass still puts keyboard focus in the new page.
  const skip = page.getByTestId('skip-to-content')
  await skip.focus()
  await expect(skip).toBeFocused()
  await skip.press('Enter')
  await expect(page.locator('main#hilos-main-content')).toBeFocused()
  await expect(page.getByTestId('page-title')).toHaveText(await page.title())
}

/** Retain the real reply and its packet index, including data-only publications. */
export function communicationsChannelReplies(
  log: DeliveryFrameLog,
  pageKey: string,
  afterPacket = 0,
) {
  return log.packets.slice(afterPacket).flatMap((packet) =>
    packet.frames.flatMap((frame) => {
      const envelope = object(frame.wire)
      const data = object(envelope.data)
      if (envelope.type !== 'page_response' || data.page !== pageKey) return []
      if (frame.failure) throw new Error('Invalid Communications page reply')
      const payload = object(data.payload)
      return [
        {
          packet,
          payload,
          summary: channelSummarySchema.parse(
            object(payload.data).channelSummary,
          ),
        },
      ]
    }),
  )
}

/** A setting change reaches an already-open page without carrying a new window. */
export async function expectCommunicationsSummaryUpdate(
  expect: Expect,
  log: DeliveryFrameLog,
  pageKey: string,
  afterPacket: number,
  summary: HilosChannelSummary,
): Promise<void> {
  await expect
    .poll(
      () =>
        communicationsChannelReplies(log, pageKey, afterPacket).at(-1)?.summary,
    )
    .toEqual(summary)
  const reply = communicationsChannelReplies(log, pageKey, afterPacket).at(-1)!
  expect(Object.keys(reply.payload)).toEqual(['data'])
  expect(Object.keys(object(reply.payload.data))).toEqual(['channelSummary'])
}

/** The Overview renders the permitted summary and never opens a fields window. */
export async function expectCommunicationsOverviewSummary(
  expect: Expect,
  page: Page,
  summary: HilosChannelSummary,
): Promise<void> {
  const configuration = summary.configured
    ? 'Configured'
    : `${summary.missingFields} missing`
  await expect(page.getByTestId('channel-head-configured')).toHaveText(
    configuration,
  )
  for (const [id, value] of [
    ['configuration', configuration],
    ['fields', String(summary.fieldCount)],
    ['overridden', String(summary.overriddenFieldCount)],
  ]) {
    await expect(
      page.getByTestId(`channel-summary-${id}`).locator('.fs-4'),
    ).toHaveText(value!)
  }
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
}

/** Save a non-secret field through its existing editor and await the reply. */
export async function saveCommunicationsField(
  expect: Expect,
  page: Page,
  field: string,
  value: string,
): Promise<void> {
  await shownByTestId(page, `hilos-channel-field-edit-${field}`).click()
  const input = page.getByTestId('hilos-channel-edit-value')
  await expect(input).toBeVisible()
  await input.fill('')
  await input.pressSequentially(value, { delay: 10 })
  const save = page.getByTestId('hilos-channel-edit-save')
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  await save.focus()
  await save.click()
  await expect(input).toHaveCount(0)
  await expect(
    shownByTestId(page, `hilos-channel-field-reset-${field}`),
  ).toBeEnabled()
}

/** Reset through the confirmation dialog and wait until the live row settles. */
export async function resetCommunicationsField(
  expect: Expect,
  page: Page,
  field: string,
): Promise<void> {
  const reset = shownByTestId(page, `hilos-channel-field-reset-${field}`)
  await expect(reset).toBeEnabled()
  await reset.click()
  const confirm = page.getByTestId('hilos-channel-reset-confirm')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()
  await expect(confirm).toHaveCount(0)
  await expect(reset).toBeDisabled()
}
