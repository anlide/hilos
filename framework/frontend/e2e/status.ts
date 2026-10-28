import type { Expect, Locator, Page } from '@playwright/test'
import {
  statusOverviewSchema,
  type HilosStatusOverviewData,
} from '../core/src/admin/status/index.js'
import { deliveryObject, watchDeliveryFrames } from './deliveryFrames.js'
import { clickSubmit } from './input.js'
import { watchSocketFrames, type SocketFrame } from './socket.js'

export const STATUS_PATH = '/hilos/status'
export const STATUS_COMPONENTS_PATH = '/hilos/status/components'
export const STATUS_LEAD =
  'A public status page: service health, incidents, and planned work.'

/** Real single/batch deliveries, parsed without replacing their transport evidence. */
export function watchStatusFrames(page: Page) {
  const frames: SocketFrame[] = []
  const overviews: HilosStatusOverviewData[] = []
  const replies: Record<string, unknown>[] = []
  const components: Record<string, unknown>[] = []
  const delivery = watchDeliveryFrames(page)
  const watcher = watchSocketFrames(page, (frame) => {
    frames.push(frame)
    const wire = deliveryObject(frame.wire)
    const data = deliveryObject(wire.data)
    if (wire.type !== 'page_response') return
    const payload = deliveryObject(data.payload)
    if (data.page === 'hilos_status') {
      overviews.push(
        statusOverviewSchema.parse(deliveryObject(payload.data).status),
      )
      replies.push(payload)
    }
    if (data.page === 'hilos_status_components') {
      components.push(
        deliveryObject(deliveryObject(payload.windows).hilosStatusComponents),
      )
    }
  })
  return {
    frames,
    overviews,
    replies,
    components,
    sent: delivery.sent,
    latest: () => overviews.at(-1),
    stop() {
      watcher.stop()
      delivery.stop()
    },
  }
}

/** The shared head, three real addresses and the section's sole heading. */
export async function expectStatusHead(
  expect: Expect,
  page: Page,
  components: boolean | 'subscribers' = false,
): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText('Status')
  await expect(page.getByTestId('hilos-page-head')).toContainText(STATUS_LEAD)
  await expect(
    page.getByTestId('hilos-breadcrumb').getByRole('listitem'),
  ).toHaveText(['Hilos', 'Status'])
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveAccessibleName('Status pages')
  await expect(tabs.getByRole('link')).toHaveCount(3)
  for (const [key, label, href, active] of [
    ['hilos_status', 'Overview', STATUS_PATH, !components],
    [
      'hilos_status_components',
      'Components',
      STATUS_COMPONENTS_PATH,
      components === true,
    ],
    [
      'hilos_status_subscribers',
      'Subscribers',
      '/hilos/status/subscribers',
      components === 'subscribers',
    ],
  ] as const) {
    const tab = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(tab).toHaveText(label)
    await expect(tab).toHaveAttribute('href', href)
    if (active) await expect(tab).toHaveAttribute('aria-current', 'page')
    else await expect(tab).not.toHaveAttribute('aria-current')
  }
}

/** An incident operation settles only after its real server success reply. */
export async function submitStatusIncident(
  expect: Expect,
  page: Page,
  log: ReturnType<typeof watchStatusFrames>,
  action: string,
  incidentId: number,
): Promise<void> {
  const start = log.frames.length
  const sentStart = log.sent.length
  const button = page.getByTestId('hilos-status-incident-confirm')
  await clickSubmit(expect, button)
  await expect(page.getByTestId('hilos-status-incident-form')).toBeHidden()
  const requests = log.sent.slice(sentStart).filter(({ wire }) => {
    const frame = deliveryObject(wire)
    return frame.type === 'action' && frame.action === action
  })
  expect(requests).toHaveLength(1)
  const request = requests[0]
  const envelope = deliveryObject(request.wire)
  expect(deliveryObject(envelope.data)).toMatchObject({ incidentId })
  expect(envelope.requestId).toEqual(expect.any(String))
  await expect
    .poll(() =>
      log.frames.slice(start).some(({ wire, socket }) => {
        const frame = deliveryObject(wire)
        const data = deliveryObject(frame.data)
        return (
          frame.type === 'action_success' &&
          data.action === action &&
          socket === request.socket &&
          (frame.requestId ?? data.requestId) === envelope.requestId
        )
      }),
    )
    .toBe(true)
}

/** Keyboard opening, naming, initial focus, trap, Escape and return on a real modal. */
export async function expectStatusIncidentDialog(
  expect: Expect,
  page: Page,
  opener: Locator,
  title: string,
): Promise<void> {
  await opener.focus()
  await opener.press('Enter')
  const modal = page.getByTestId('modal')
  await expect(modal).toHaveAttribute('role', 'dialog')
  await expect(modal).toHaveAttribute('aria-modal', 'true')
  await expect(modal).toHaveAccessibleName(title)
  await expect(modal).toBeFocused()
  const cancel = page.getByTestId('hilos-status-incident-cancel')
  const confirm = page.getByTestId('hilos-status-incident-confirm')
  await expect(cancel).toHaveAccessibleName('Cancel')
  await expect(confirm).toHaveAccessibleName(
    title === 'Do not publish' ? title : 'Publish now',
  )
  await confirm.focus()
  await page.keyboard.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(confirm).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(modal).toBeHidden()
  await expect(opener).toBeFocused()
}
