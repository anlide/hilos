import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HilosConnection,
  ScopeManager,
  PAGE_SIGNAL_SCHEMAS,
  LEGAL_AGREEMENTS_SIGNAL_SCHEMAS,
  bindPageScope,
  hilosPublicLegalNavigation,
  createAppPageRouter,
  type ActionLifecycle,
  type ActionResult,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
  type WebSocketLike,
} from '../../src/base/index.js'
import {
  clause,
  first,
  privacyAgreement,
  privacySection,
  termsAgreement,
  termsSection,
} from './fixtures.js'

/** DOM adapter supplied by the actual SDK's component test. */
export interface PublicHistoryMount {
  settle(): Promise<void>
  change(run: () => void): Promise<void>
  unmount(): void
}
export type DrawPublicHistory = (
  context: HilosLegalContext,
  document: HilosLegalDocumentKey,
) => PublicHistoryMount

/** Script only the browser socket; all page, table and reader machinery is real. */
class Socket implements WebSocketLike {
  readonly sent: string[] = []
  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()
  send(value: string | ArrayBuffer | Blob): void {
    if (typeof value === 'string') this.sent.push(value)
  }
  close(): void {}
  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
  }
  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }
  message(type: string, data: unknown): void {
    this.emit('message', { data: JSON.stringify({ type, data }) })
  }
}

/** Keep a real cold-entry answer available to a lazy mount through the connection buffer. */
function world(document: HilosLegalDocumentKey, signedIn = false) {
  const page =
    document === 'terms' ? 'hilos_terms_history' : 'hilos_privacy_history'
  const tableKey =
    document === 'terms' ? 'hilosTermsHistory' : 'hilosPrivacyHistory'
  const text = document === 'terms' ? termsSection : privacySection
  const socket = new Socket()
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    projectSchemas: {
      ...PAGE_SIGNAL_SCHEMAS,
      ...LEGAL_AGREEMENTS_SIGNAL_SCHEMAS,
    },
    webSocketFactory: () => socket,
  })
  connection.connect()
  socket.emit('open')
  socket.message('handshake', { build: 'test' })
  const scopes = new ScopeManager()
  scopes.session.data.set(
    'currentUser',
    signedIn ? { type: 'user', id: 2 } : null,
  )
  const pages = bindPageScope(connection, scopes)
  pages.releaseOnSession()
  pages.subscribe(page)
  const requests: {
    name: string
    payload: unknown
    resolve(value: ActionResult): void
    reject(reason: unknown): void
  }[] = []
  const actions = {
    dispatch(name: string, payload: unknown) {
      return {
        done: new Promise<ActionResult>((resolve, reject) =>
          requests.push({ name, payload, resolve, reject }),
        ),
      }
    },
  } as unknown as ActionLifecycle
  const answer = (published = true): void => {
    const rows = published ? [...text.revisions].reverse() : []
    const agreement =
      document === 'terms' ? termsAgreement('covered', first) : privacyAgreement
    socket.message('page_response', {
      page,
      payload: {
        data: {
          [document === 'terms' ? 'legalTerms' : 'legalPrivacy']: published
            ? text
            : null,
          ...(signedIn ? { legalAgreements: { documents: [agreement] } } : {}),
        },
        windows: {
          [tableKey]: {
            rows: rows.map((revision) => ({
              rowKey: revision.revisionId,
              slots: { revision: { ...revision } },
            })),
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: rows.length ? { rowKey: rows[0]!.revisionId } : null,
            lastAnchor: rows.length
              ? { rowKey: rows.at(-1)!.revisionId }
              : null,
            limit: 25,
            sort: [],
          },
        },
      },
    })
  }
  return {
    context: { connection, scopes, actions },
    socket,
    answer,
    requests,
    text,
    page,
    tableKey,
  }
}

export function publicHistoryNavigation(document: HilosLegalDocumentKey) {
  const page =
    document === 'terms' ? 'hilos_terms_history' : 'hilos_privacy_history'
  const router = createAppPageRouter({})
  return hilosPublicLegalNavigation(page, (key, params) =>
    router.path(key, params),
  )
}

function one(id: string, root: ParentNode = document): HTMLElement {
  const value = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  expect(value, id).not.toBeNull()
  return value!
}

/** The shared behavior contract is run over each kit's real components. */
export function publicHistoryComponentTests(draw: DrawPublicHistory): void {
  const cleanups: (() => void)[] = []
  afterEach(() => {
    for (const stop of cleanups.splice(0).reverse()) stop()
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
  })
  function mount(
    document: HilosLegalDocumentKey,
    signedIn = false,
    answered = true,
  ) {
    const h = world(document, signedIn)
    cleanups.push(() => h.context.connection.close())
    if (answered) h.answer()
    const view = draw(h.context, document)
    cleanups.push(() => view.unmount())
    return { ...h, view }
  }
  describe.each(['terms', 'privacy'] as const)('%s history', (documentKey) => {
    it('uses the native skeleton, empty and refused window without mount actions', async () => {
      const h = mount(documentKey, false, false)
      await h.view.settle()
      expect(
        document.querySelector(
          '[data-id="hilos-viewport-table"] [aria-busy="true"]',
        ),
      ).not.toBeNull()
      expect(h.requests).toEqual([])
      await h.view.change(() => h.answer(false))
      expect(
        document.querySelector('[data-id="public-legal-history-open"]'),
      ).toBeNull()
      expect(one('public-legal-history').textContent).toContain(
        'Nothing here yet',
      )
      await h.view.change(() =>
        h.socket.message('table_window_refused', {
          page: h.page,
          tableKey: h.tableKey,
          errorCode: 'unavailable',
        }),
      )
      expect(one('public-legal-history').textContent).toContain(
        'List unavailable',
      )
      await h.view.change(() => h.answer())
      expect(
        document.querySelector('table [data-id="public-legal-history-open"]'),
      ).not.toBeNull()
      const subscriptions = h.socket.sent
        .map((frame) => JSON.parse(frame) as { type: string; data?: unknown })
        .filter((frame) => frame.type === 'page_subscribe')
      expect(subscriptions).toHaveLength(1)
    })

    it('renders public metadata in server order, its two tabs and reader marks', async () => {
      const h = mount(documentKey, true)
      await h.view.settle()
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      const heading = one('hilos-page-head-title')
      expect(heading.textContent?.trim()).toBe(
        documentKey === 'terms' ? 'Terms' : 'Privacy',
      )
      const table = document.querySelector('table')!
      expect(table.getAttribute('aria-labelledby')).toBe(heading.id)
      expect(
        [
          ...table.querySelectorAll('[data-id="public-legal-history-open"]'),
        ].map((button) => button.getAttribute('data-revision')),
      ).toEqual([...h.text.revisions].reverse().map((row) => row.revisionId))
      expect(
        one('public-legal-history-current', table).getAttribute(
          'data-revision',
        ),
      ).toBe(h.text.current.revisionId)
      expect(
        one('public-legal-history-current', table).textContent?.trim(),
      ).toBe('in force')
      expect(
        one('public-legal-history-accepted', table).getAttribute(
          'data-revision',
        ),
      ).toBe(first.revisionId)
      expect(
        one('public-legal-history-accepted', table).textContent?.trim(),
      ).toBe('you accepted this')
      expect(table.textContent).toContain('17 September 2026')
      expect(table.textContent).toContain('First revision')
      const tabs = one('hilos-page-tabs')
      expect(
        [...tabs.querySelectorAll('a')].map((link) =>
          link.getAttribute('href'),
        ),
      ).toEqual([`/${documentKey}`, `/${documentKey}/history`])
      expect(
        tabs.querySelector('[aria-current="page"]')?.getAttribute('href'),
      ).toBe(`/${documentKey}/history`)
      expect(document.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
      expect(h.requests).toEqual([])
    })

    it.each(['footer', 'escape', 'cross'] as const)(
      'closes a pending read by %s, returns focus and ignores its late answer',
      async (way) => {
        const h = mount(documentKey)
        await h.view.settle()
        const opener = one(
          'public-legal-history-open',
          document.querySelector('table')!,
        )
        opener.focus()
        await h.view.change(() => opener.click())
        expect(
          h.requests.map(({ name, payload }) => ({ name, payload })),
        ).toEqual([
          {
            name: `hilos_${documentKey}_revision_text`,
            payload: {
              document: documentKey,
              revisionId: h.text.current.revisionId,
            },
          },
        ])
        const modal = one('modal')
        expect(modal.getAttribute('role')).toBe('dialog')
        expect(modal.getAttribute('aria-modal')).toBe('true')
        expect(modal.getAttribute('aria-label')).toContain(
          documentKey === 'terms'
            ? 'Terms — revision of'
            : 'Privacy — revision of',
        )
        expect(document.activeElement).toBe(modal)
        expect(one('legal-dialog-loading').textContent).toContain('Loading')
        expect(one('public-legal-history-close').matches(':disabled')).toBe(
          false,
        )
        expect(one('modal-close').matches(':disabled')).toBe(false)
        expect(opener.getAttribute('data-hilos-focus-return')).toBe(
          'public-legal-history-open',
        )
        await h.view.change(() => {
          if (way === 'escape')
            modal.dispatchEvent(
              new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
            )
          else
            one(
              way === 'cross' ? 'modal-close' : 'public-legal-history-close',
            ).click()
        })
        expect(
          document.querySelector('[data-id="public-legal-history-modal"]'),
        ).toBeNull()
        expect(document.activeElement).toBe(opener)
        await h.view.change(() =>
          h.requests[0]!.resolve({
            reply: {
              document: documentKey,
              revisionId: h.text.current.revisionId,
              clauses: [clause],
            },
          }),
        )
        expect(
          document.querySelector('[data-id="public-legal-history-modal"]'),
        ).toBeNull()
      },
    )

    it.each([true, false])(
      'returns focus through the table after its opener leaves, replacement=%s',
      async (replacement) => {
        // DOM test environments have no layout; the ordinary table fallback
        // correctly refuses a zero-sized root. Supply the rendered geometry.
        const layout = vi
          .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
          .mockImplementation(function (this: HTMLElement) {
            return this.closest('[hidden]')
              ? new DOMRect()
              : new DOMRect(0, 0, 400, 40)
          })
        cleanups.push(() => layout.mockRestore())
        const h = mount(documentKey)
        await h.view.settle()
        const opener = one(
          'public-legal-history-open',
          document.querySelector('table')!,
        )
        opener.focus()
        await h.view.change(() => opener.click())
        await h.view.change(() => h.answer(false))
        expect(opener.isConnected).toBe(false)
        if (replacement) await h.view.change(() => h.answer())
        const modal = one('modal')
        expect(document.activeElement).toBe(modal)
        await h.view.change(() => one('public-legal-history-close').click())
        const target = replacement
          ? one('public-legal-history-open', document.querySelector('table')!)
          : one('hilos-viewport-table')
        expect(document.activeElement).toBe(target)
      },
    )

    it('keeps the newer reading when a previously closed request answers later', async () => {
      const h = mount(documentKey)
      await h.view.settle()
      await h.view.change(() =>
        one(
          'public-legal-history-open',
          document.querySelector('table')!,
        ).click(),
      )
      await h.view.change(() => one('public-legal-history-close').click())
      const old = document.querySelector<HTMLElement>(
        `table [data-id="public-legal-history-open"][data-revision="${first.revisionId}"]`,
      )!
      await h.view.change(() => old.click())
      await h.view.change(() =>
        h.requests[0]!.resolve({
          reply: {
            document: documentKey,
            revisionId: h.text.current.revisionId,
            clauses: [{ ...clause, statement: 'Outdated answer' }],
          },
        }),
      )
      expect(
        one('public-legal-history-modal').getAttribute('data-revision'),
      ).toBe(first.revisionId)
      expect(one('public-legal-history-modal').textContent).not.toContain(
        'Outdated answer',
      )
      await h.view.change(() =>
        h.requests[1]!.resolve({
          reply: {
            document: documentKey,
            revisionId: first.revisionId,
            clauses: [clause],
          },
        }),
      )
      expect(one('public-legal-history-modal').textContent).toContain(
        clause.statement,
      )
    })

    it('reads the exact old revision and shows an action refusal inside its window', async () => {
      const h = mount(documentKey)
      await h.view.settle()
      const opener = document.querySelector<HTMLElement>(
        `table [data-id="public-legal-history-open"][data-revision="${first.revisionId}"]`,
      )!
      await h.view.change(() => opener.click())
      expect(h.requests[0]!.payload).toEqual({
        document: documentKey,
        revisionId: first.revisionId,
      })
      await h.view.change(() =>
        h.requests[0]!.reject(
          new ActionError(
            `hilos_${documentKey}_revision_text`,
            'fail',
            'This revision cannot be read.',
          ),
        ),
      )
      expect(one('legal-dialog-refusal').textContent).toContain(
        'This revision cannot be read.',
      )
      await h.view.change(() => one('public-legal-history-close').click())
      await h.view.change(() => opener.click())
      await h.view.change(() =>
        h.requests[1]!.resolve({
          reply: {
            document: documentKey,
            revisionId: first.revisionId,
            clauses: [clause],
          },
        }),
      )
      expect(one('public-legal-history-modal').textContent).toContain(
        clause.statement,
      )
      expect(one('modal').getAttribute('aria-label')).toContain(
        '17 September 2026',
      )
    })

    it('clears personal marks and the reader when identity changes and releases a departed page', async () => {
      const h = mount(documentKey, true)
      await h.view.settle()
      await h.view.change(() =>
        one(
          'public-legal-history-open',
          document.querySelector('table')!,
        ).click(),
      )
      await h.view.change(() =>
        h.context.scopes.session.data.set('currentUser', null),
      )
      expect(
        document.querySelector('[data-id="public-legal-history-accepted"]'),
      ).toBeNull()
      expect(
        document.querySelector('[data-id="public-legal-history-modal"]'),
      ).toBeNull()
      await h.view.change(() =>
        h.requests[0]!.resolve({
          reply: {
            document: documentKey,
            revisionId: h.text.current.revisionId,
            clauses: [clause],
          },
        }),
      )
      expect(
        document.querySelector('[data-id="public-legal-history-modal"]'),
      ).toBeNull()
      await h.view.change(() => h.context.scopes.openPage('elsewhere'))
      const sent = h.socket.sent.length
      h.view.unmount()
      h.answer()
      expect(h.socket.sent).toHaveLength(sent)
    })
  })
}
