import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalAcceptancesPage } from '../src/admin/legal/HilosLegalAcceptancesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  ActionError,
  bindAdminAccess,
  hilosBrowserTimeZone,
  hilosTimeZone,
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ingest,
  ScopeManager,
  type HilosLegalAcceptancesExportNode,
  type HilosLegalContext,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { readerTimeZone } from './readerTimeZone.js'

/** The acceptance's person fields, as the server sent them to this viewer. */
interface PersonFields {
  name: unknown
  email: unknown
}

interface AcceptanceFixture extends PersonFields {
  rowKey: number
  acceptedAt: unknown
}

const ACCEPTED_AT = Date.parse('2026-10-24T03:30:00Z')

/** The live window is real; only its transport is replaced, and the rows arrive by `push`. */
function harness() {
  const listeners: Array<(frame: never) => void> = []
  const requests = { registrations: 0, viewports: 0, windows: 0 }
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LEGAL_ACCEPTANCES)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        if (event === 'tableWindow') listeners.push(listener)
        return () => {}
      },
      registerTableWindow() {
        requests.registrations += 1
      },
      unregisterTableWindow() {},
      sendTableViewport() {
        requests.viewports += 1
      },
      sendTableRendered() {},
      sendTableFacets() {},
      sendTableRowFocus() {},
    },
    actions: {},
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_ACCEPTANCES,
      params: {},
      admin: true,
    }),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return {
    context,
    router,
    requests,
    push(acceptances: AcceptanceFixture[]) {
      requests.windows += 1
      for (const listener of listeners)
        listener({
          data: {
            page: HilosPages.LEGAL_ACCEPTANCES,
            tableKey: 'hilosLegalAcceptances',
            rows: acceptances.map(({ rowKey, name, email, acceptedAt }) => ({
              rowKey,
              slots: {
                acceptance: {
                  userId: rowKey,
                  name,
                  email,
                  document: 'terms',
                  revisionId: 'current',
                  declared: true,
                  acceptedAt,
                },
              },
            })),
            totalCount: acceptances.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
          },
        } as never)
    },
  }
}

function mountOver(person: PersonFields): HTMLElement {
  const h = harness()
  const view = render(
    <HilosRouterContext.Provider value={h.router}>
      <HilosLegalAcceptancesPage context={h.context} />
    </HilosRouterContext.Provider>,
  )
  act(() => h.push([{ rowKey: 42, ...person, acceptedAt: ACCEPTED_AT }]))
  return view.container.querySelector(
    '[data-id="legal-acceptance-row"]',
  ) as HTMLElement
}

const releases: Array<() => void> = []
afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
})

function formatIndependent(moment: number, timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    timeZone,
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(moment)
}

describe('HilosLegalAcceptancesPage (HIL-1260)', () => {
  it('draws the mark for a hidden name and a hidden email, and says nothing about email', () => {
    const record = mountOver({ name: HIDDEN_VALUE, email: HIDDEN_VALUE })

    expect(record.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(record.textContent).not.toContain('No verified email')
  })

  it('says "No verified email" only when there is none', () => {
    const record = mountOver({ name: 'Reader', email: null })

    expect(record.querySelector('strong')?.textContent).toBe('Reader')
    expect(record.textContent).toContain('No verified email')
    expect(record.querySelector('[data-id="hilos-hidden"]')).toBeNull()
  })

  it('draws accepted moments live in the reader zone without replacing the table window', () => {
    const reader = readerTimeZone()
    try {
      const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
      const readerZone =
        browserZone === 'Pacific/Honolulu' ? 'Asia/Tokyo' : 'Pacific/Honolulu'
      const h = harness()
      act(() => reader.choose('UTC'))
      const view = render(
        <HilosRouterContext.Provider value={h.router}>
          <HilosLegalAcceptancesPage context={h.context} />
        </HilosRouterContext.Provider>,
      )
      const table = view.container.querySelector('table')
      const fixtures: AcceptanceFixture[] = [
        {
          rowKey: 42,
          name: 'Reader',
          email: 'reader@example.com',
          acceptedAt: ACCEPTED_AT,
        },
        {
          rowKey: 43,
          name: 'Epoch',
          email: 'epoch@example.com',
          acceptedAt: 0,
        },
        {
          rowKey: 44,
          name: 'Missing',
          email: 'missing@example.com',
          acceptedAt: null,
        },
        {
          rowKey: 45,
          name: 'Invalid',
          email: 'invalid@example.com',
          acceptedAt: 8_640_000_000_000_001,
        },
      ]
      act(() => h.push(fixtures))

      const acceptanceTime = (rowKey: number): HTMLElement =>
        view.container.querySelector(
          `[data-id="hilos-table-row-${rowKey}"] [data-id="legal-acceptance-accepted-at"]`,
        ) as HTMLElement
      const firstRow = view.container.querySelector(
        '[data-id="hilos-table-row-42"]',
      )
      expect(acceptanceTime(42).textContent).toBe(
        formatIndependent(ACCEPTED_AT, 'UTC'),
      )
      expect(acceptanceTime(42).textContent).not.toBe('')
      expect(acceptanceTime(43).textContent).toBe(formatIndependent(0, 'UTC'))
      expect(acceptanceTime(43).textContent).not.toBe('')
      expect(acceptanceTime(44).textContent).toBe('—')
      expect(acceptanceTime(45).textContent).toBe('—')
      expect(readerZone).not.toBe(browserZone)

      const beforeZoneChange = { ...h.requests }
      act(() => reader.choose(readerZone))

      expect(hilosTimeZone.get()).toBe(readerZone)
      expect(h.requests).toEqual(beforeZoneChange)
      expect(view.container.querySelector('table')).toBe(table)
      expect(
        view.container.querySelector('[data-id="hilos-table-row-42"]'),
      ).toBe(firstRow)
      expect(acceptanceTime(42).textContent).toBe(
        formatIndependent(ACCEPTED_AT, readerZone),
      )
      expect(acceptanceTime(43).textContent).toBe(
        formatIndependent(0, readerZone),
      )
      expect(acceptanceTime(43).textContent).not.toBe('')
      expect(acceptanceTime(44).textContent).toBe('—')
      expect(acceptanceTime(45).textContent).toBe('—')
    } finally {
      cleanup()
      reader.release()
    }
  })
})

const preparing = {
  state: 'preparing',
  document: 'terms',
  revisionId: 'current',
  search: 'anna',
  requestedAt: 1000,
  finishedAt: null,
  expiresAt: null,
  sizeBytes: null,
  records: null,
} as const satisfies HilosLegalAcceptancesExportNode
const ready = {
  state: 'ready',
  document: null,
  revisionId: null,
  search: null,
  requestedAt: 1000,
  finishedAt: 2000,
  expiresAt: 3000,
  sizeBytes: 456,
  records: 12,
} as const satisfies HilosLegalAcceptancesExportNode

function deferredReply() {
  let resolve!: () => void
  let reject!: (error: ActionError) => void
  const done = new Promise<{ reply: object }>((yes, no) => {
    resolve = () => yes({ reply: {} })
    reject = no
  })
  return { done, resolve, reject }
}

/**
 * Mount the page with its export: the section it opens with, the frames after
 * it, and a confirmation step whose replies the test can hold.
 *
 * @param initial The export the page answer carries, if any.
 * @param required Whether the server asks for password confirmation.
 */
function mountExport(
  initial?: HilosLegalAcceptancesExportNode,
  required = false,
) {
  const frames: Array<(signal: ProjectSignal) => void> = []
  const sent: Array<{ name: string; data: unknown }> = []
  const pending = new Map<string, ReturnType<typeof deferredReply>>()
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.LEGAL_ACCEPTANCES)
  if (initial !== undefined) page.data.set('legalAcceptancesExport', initial)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') frames.push(listener)
        return () => {}
      },
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
      sendTableRendered() {},
      sendTableFacets() {},
      sendTableRowFocus() {},
    },
    actions: {
      dispatch(name: string, data: unknown) {
        sent.push({ name, data })
        return {
          done:
            pending.get(name)?.done ??
            Promise.resolve({
              reply:
                name === 'hilos_step_up_start'
                  ? {
                      required,
                      method: 'password',
                      purpose: 'export acceptance records',
                    }
                  : {},
            }),
        }
      },
    },
  } as unknown as HilosLegalContext
  const view = render(
    <HilosRouterContext.Provider value={harness().router}>
      <HilosLegalAcceptancesPage context={context} />
    </HilosRouterContext.Provider>,
  )
  const find = (id: string) =>
    view.container.querySelector<HTMLElement>(`[data-id="${id}"]`)

  return {
    sent,
    find,
    scopes,
    unmount: view.unmount,
    hold(name: string) {
      const reply = deferredReply()
      pending.set(name, reply)
      return reply
    },
    button: () => find('legal-acceptances-export') as HTMLButtonElement,
    emit(node: HilosLegalAcceptancesExportNode | null) {
      act(() => {
        for (const listener of frames)
          listener({
            type: 'hilos_legal_acceptances_export_state',
            data: { legalAcceptancesExport: node },
          } as unknown as ProjectSignal)
      })
    },
  }
}

function byId(id: string): HTMLElement {
  return document.querySelector(`[data-id="${id}"]`) as HTMLElement
}

function cancelButton(): HTMLButtonElement {
  return [...byId('modal').querySelectorAll('button')].find(
    (button) => button.textContent === 'Cancel',
  )!
}

/** User exits and repeat submission cannot finish an unanswered request. */
function probePending(): void {
  const modal = byId('modal')
  const confirm = byId('legal-acceptances-export-confirm')
  expect(cancelButton().matches(':disabled')).toBe(true)
  expect(byId('modal-close').matches(':disabled')).toBe(true)
  expect(confirm.getAttribute('aria-busy')).toBe('true')
  expect(confirm.closest('fieldset:disabled')).toBeNull()
  for (const gesture of [
    () => fireEvent.click(cancelButton()),
    () => fireEvent.click(byId('modal-close')),
    () => fireEvent.keyDown(modal, { key: 'Escape' }),
    () => fireEvent.click(modal),
    () => fireEvent.click(confirm),
    // The submit event is the Enter path; jsdom does not synthesize it from a key.
    () => fireEvent.submit(byId('legal-acceptances-export-step-up')),
  ]) {
    gesture()
    expect(byId('modal')).toBe(modal)
  }
  expect(byId('modal-confirm')).toBeNull()
}

async function confirmExport(): Promise<void> {
  await act(async () => {
    fireEvent.click(byId('legal-acceptances-export-confirm'))
  })
}

describe('HilosLegalAcceptancesPage export (HIL-1234)', () => {
  it('draws the Export button and nothing under it while there is no export', () => {
    const w = mountExport()

    expect(w.button().textContent).toBe('Export')
    expect(w.button().disabled).toBe(false)
    expect(w.find('legal-acceptances-export-filter')).toBeNull()
    expect(w.find('legal-acceptances-export-download')).toBeNull()
  })

  it('draws the three states of the export and the link of a ready one', () => {
    const w = mountExport(preparing)

    expect(w.find('legal-acceptances-export-preparing')?.textContent).toContain(
      'Preparing the export… Started at',
    )
    expect(w.find('legal-acceptances-export-filter')?.textContent).toBe(
      'Terms · current · “anna”',
    )
    expect(w.button().disabled).toBe(true)

    w.emit(ready)
    expect(w.find('legal-acceptances-export-ready')?.textContent).toContain(
      'Export ready · 12 records · 456 B · available until',
    )
    const link = w.find('legal-acceptances-export-download')
    expect(link?.getAttribute('href')).toBe('/_hilos/legal-acceptances-export')
    expect(link?.hasAttribute('download')).toBe(true)
    expect(link?.textContent).toBe('Download')
    expect(w.button().disabled).toBe(false)

    w.emit({ ...ready, state: 'failed', sizeBytes: null, records: null })
    expect(w.find('legal-acceptances-export-failed')?.textContent).toBe(
      'We could not prepare the export.',
    )
    expect(w.find('legal-acceptances-export-download')).toBeNull()
  })

  it('orders the export when the confirmation step is skipped', async () => {
    const w = mountExport()

    await act(async () => {
      fireEvent.click(w.button())
    })

    expect(w.sent.map((entry) => entry.name)).toEqual([
      'hilos_step_up_start',
      'legal_acceptances_export',
    ])
    expect(w.sent.at(-1)?.data).toEqual({
      document: null,
      revisionId: null,
      search: null,
    })
  })

  it('locks proof and order through their replies, keeps refused drafts, and releases the window before archive preparation ends', async () => {
    const w = mountExport(undefined, true)
    await act(async () => fireEvent.click(w.button()))
    const modal = byId('modal')
    const field = byId('step-up-password') as HTMLInputElement
    const body = field.closest('fieldset')!
    const room = byId('step-up-error-slot')
    const orderRoom = byId('legal-acceptances-export-order-error-slot')
    fireEvent.change(field, { target: { value: 'my proof' } })
    expect(field.matches(':disabled')).toBe(false)
    expect(cancelButton().matches(':disabled')).toBe(false)

    const proof = w.hold('hilos_step_up_confirm')
    await confirmExport()
    expect(field.matches(':disabled')).toBe(true)
    probePending()
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
    ])
    await act(async () =>
      proof.reject(
        new ActionError('hilos_step_up_confirm', 'fail', 'Proof refused'),
      ),
    )
    expect(byId('modal')).toBe(modal)
    expect(byId('step-up-password')).toBe(field)
    expect(field.closest('fieldset')).toBe(body)
    expect(field.value).toBe('my proof')
    expect(field.matches(':disabled')).toBe(false)
    expect(cancelButton().matches(':disabled')).toBe(false)
    expect(byId('modal-close').matches(':disabled')).toBe(false)
    expect(byId('step-up-error-slot')).toBe(room)
    expect(byId('step-up-error').textContent).toContain('Proof refused')

    const retryProof = w.hold('hilos_step_up_confirm')
    const order = w.hold('legal_acceptances_export')
    await confirmExport()
    await act(async () => retryProof.resolve())
    expect(field.matches(':disabled')).toBe(true)
    probePending()
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
      'hilos_step_up_confirm',
      'legal_acceptances_export',
    ])
    expect(w.sent.at(-1)?.data).toEqual({
      document: null,
      revisionId: null,
      search: null,
    })
    await act(async () =>
      order.reject(
        new ActionError(
          'legal_acceptances_export',
          'fail',
          'Only one export at a time',
        ),
      ),
    )
    expect(byId('modal')).toBe(modal)
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('my proof')
    expect(field.matches(':disabled')).toBe(false)
    expect(cancelButton().matches(':disabled')).toBe(false)
    expect(byId('legal-acceptances-export-order-error-slot')).toBe(orderRoom)
    expect(byId('legal-acceptances-export-order-error').textContent).toContain(
      'Only one export at a time',
    )
    expect(
      byId('legal-acceptances-export-confirm').getAttribute('aria-busy'),
    ).toBeNull()

    const finalProof = w.hold('hilos_step_up_confirm')
    const finalOrder = w.hold('legal_acceptances_export')
    await confirmExport()
    await act(async () => finalProof.resolve())
    await act(async () => finalOrder.resolve())
    expect(byId('modal')).toBeNull()
    expect(
      w.sent.filter((request) => request.name === 'hilos_step_up_confirm'),
    ).toHaveLength(3)
    w.emit(preparing)
    expect(byId('modal')).toBeNull()
    expect(w.button().matches(':disabled')).toBe(true)
    expect(w.button().getAttribute('aria-busy')).toBeNull()
    expect(w.find('legal-acceptances-export-preparing')).not.toBeNull()
    w.emit(ready)
    expect(w.button().matches(':disabled')).toBe(false)
    await act(async () => fireEvent.click(w.button()))
    expect(byId('step-up-password').matches(':disabled')).toBe(false)
    expect(cancelButton().matches(':disabled')).toBe(false)
  })

  it.each(['cancel', 'header', 'Escape', 'backdrop'] as const)(
    'restores the %s exit after a refusal and does not replay a pending gesture',
    async (exit) => {
      const w = mountExport(undefined, true)
      await act(async () => fireEvent.click(w.button()))
      fireEvent.change(byId('step-up-password'), {
        target: { value: 'my proof' },
      })
      const proof = w.hold('hilos_step_up_confirm')
      await confirmExport()
      probePending()
      await act(async () =>
        proof.reject(
          new ActionError('hilos_step_up_confirm', 'fail', 'Try again'),
        ),
      )
      const modal = byId('modal')
      expect(modal).not.toBeNull()
      if (exit === 'cancel') fireEvent.click(cancelButton())
      else if (exit === 'header') fireEvent.click(byId('modal-close'))
      else if (exit === 'Escape') fireEvent.keyDown(modal, { key: 'Escape' })
      else fireEvent.click(modal)
      expect(byId('modal')).toBeNull()
      expect(w.sent).toHaveLength(2)
    },
  )

  it('closes on an authoritative preparing frame even while confirmation is busy', async () => {
    const w = mountExport(undefined, true)
    await act(async () => fireEvent.click(w.button()))
    fireEvent.change(byId('step-up-password'), {
      target: { value: 'my proof' },
    })
    const proof = w.hold('hilos_step_up_confirm')
    await confirmExport()
    expect(byId('step-up-password').matches(':disabled')).toBe(true)
    w.emit(preparing)
    expect(byId('modal')).toBeNull()
    await act(async () => proof.resolve())
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
    ])
    expect(w.button().matches(':disabled')).toBe(true)
    expect(w.button().getAttribute('aria-busy')).toBeNull()
  })

  it('releases a busy modal on page unmount and sends no order after its late reply', async () => {
    const w = mountExport(undefined, true)
    await act(async () => fireEvent.click(w.button()))
    fireEvent.change(byId('step-up-password'), {
      target: { value: 'my proof' },
    })
    const proof = w.hold('hilos_step_up_confirm')
    await confirmExport()
    w.unmount()
    expect(byId('modal')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    await act(async () => proof.resolve())
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
    ])
  })

  it('keeps admin view mode independent: fields and Cancel stay live while the export is disabled', async () => {
    const w = mountExport(undefined, true)
    await act(async () => fireEvent.click(w.button()))
    releases.push(bindAdminAccess(w.scopes))
    act(() => ingest(w.scopes.session, { data: { adminViewMode: true } }))
    const confirm = byId('legal-acceptances-export-confirm')
    expect(confirm.matches(':disabled')).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    expect(confirm.getAttribute('aria-busy')).toBeNull()
    expect(w.button().matches(':disabled')).toBe(true)
    expect(byId('step-up-password').matches(':disabled')).toBe(false)
    expect(cancelButton().matches(':disabled')).toBe(false)
    fireEvent.click(confirm)
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
    ])
    fireEvent.click(cancelButton())
    expect(byId('modal')).toBeNull()
  })
})
