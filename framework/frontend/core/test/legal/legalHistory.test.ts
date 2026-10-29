import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  type ActionLifecycle,
  type ActionResult,
} from '../../src/connection/actionLifecycle.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import {
  LEGAL_AGREEMENTS_SIGNAL_SCHEMAS,
  type HilosLegalAgreement,
  type HilosLegalDocumentKey,
} from '../../src/legal/legalAgreements.js'
import {
  createHilosLegalHistory,
  hilosPublicLegalNavigation,
} from '../../src/legal/legalHistory.js'
import { createHilosLegalPublicDocumentStore } from '../../src/legal/legalPublicDocument.js'
import { LEGAL_TERMS_COPY } from '../../src/legal/legalTerms.js'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { hilosTabLinks } from '../../src/routing/hilosAdmin.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../src/subscription/bindPageScope.js'
import {
  clause,
  current,
  first,
  privacyAgreement,
  privacySection,
  termsAgreement,
  termsSection,
} from './fixtures.js'

/** Only the socket is scripted; connection, scope and table bindings are real. */
class HistorySocket implements WebSocketLike {
  readonly sent: string[] = []
  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()
  send(data: string | ArrayBuffer | Blob): void {
    if (typeof data === 'string') this.sent.push(data)
  }
  close(): void {}
  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    const listeners = this.listeners.get(type) ?? []
    listeners.push(listener)
    this.listeners.set(type, listeners)
  }
  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }
  message(type: string, data: unknown): void {
    this.emit('message', { data: JSON.stringify({ type, data }) })
  }
  frames(): Record<string, unknown>[] {
    return this.sent.map(
      (frame) => JSON.parse(frame) as Record<string, unknown>,
    )
  }
}

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
  vi.useRealTimers()
})

/** Open a public history using the real connection and page-scope lifecycle. */
function world(
  document: HilosLegalDocumentKey = 'terms',
  userId: number | null = 1,
) {
  const terms = document === 'terms'
  const page = terms ? 'hilos_terms_history' : 'hilos_privacy_history'
  const tableKey = terms ? 'hilosTermsHistory' : 'hilosPrivacyHistory'
  const section = terms ? 'legalTerms' : 'legalPrivacy'
  const text = terms ? termsSection : privacySection
  const agreement: HilosLegalAgreement = terms
    ? {
        ...termsAgreement('covered', first),
        accepted: [
          { revisionId: first.revisionId, acceptedAt: 1 },
          { revisionId: current.revisionId, acceptedAt: 2 },
        ],
      }
    : privacyAgreement
  const requests: {
    name: string
    payload: unknown
    resolve(value: ActionResult): void
    reject(error: Error): void
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
  const sockets: HistorySocket[] = []
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    random: () => 0,
    projectSchemas: {
      ...PAGE_SIGNAL_SCHEMAS,
      ...LEGAL_AGREEMENTS_SIGNAL_SCHEMAS,
    },
    webSocketFactory: () => {
      const socket = new HistorySocket()
      sockets.push(socket)
      return socket
    },
  })
  connection.connect()
  const socket = sockets[0]!
  socket.emit('open')
  socket.message('handshake', { build: 'test' })
  const scopes = new ScopeManager()
  scopes.session.data.set(
    'currentUser',
    userId === null ? null : { type: 'user', id: userId },
  )
  const pages = bindPageScope(connection, scopes)
  pages.releaseOnSession()
  pages.subscribe(page)
  const history = createHilosLegalHistory(
    { connection, scopes, actions },
    document,
  )
  cleanups.push(() => {
    history.cleanup()
    connection.close()
  })
  const answer = (
    mine: HilosLegalAgreement | null = agreement,
    published = true,
    onPage = page,
    rows = [...text.revisions].reverse(),
    target = sockets.at(-1)!,
  ): void => {
    target.message('page_response', {
      page: onPage,
      payload: {
        data: {
          [section]: published ? text : null,
          ...(mine === null ? {} : { legalAgreements: { documents: [mine] } }),
        },
        windows: {
          [tableKey]: {
            rows: published
              ? rows.map((revision) => ({
                  rowKey: revision.revisionId,
                  slots: {
                    revision: { rowKey: revision.revisionId, ...revision },
                  },
                }))
              : [],
            totalCount: published ? 40 : 0,
            totalExact: true,
            firstAnchor: published ? { rowKey: rows[0]!.revisionId } : null,
            lastAnchor: published ? { rowKey: rows.at(-1)!.revisionId } : null,
            limit: 25,
            sort: [],
          },
        },
      },
    })
  }
  const group = (mine: HilosLegalAgreement): void =>
    socket.message('hilos_legal_agreements_state', { documents: [mine] })
  return {
    history,
    connection,
    scopes,
    pages,
    socket,
    sockets,
    requests,
    answer,
    group,
    agreement,
    text,
    page,
    tableKey,
    section,
    actions,
  }
}

describe('public legal navigation', () => {
  it.each([
    [HilosPages.TERMS, HilosPages.TERMS_HISTORY, 'Terms', '/terms'],
    [HilosPages.PRIVACY, HilosPages.PRIVACY_HISTORY, 'Privacy', '/privacy'],
  ])(
    'keeps %s and its history in one static tab row',
    (main, history, label, path) => {
      const routes = createAppPageRouter({})
      const resolve = (page: string) => routes.path(page, {})
      for (const page of [main, history]) {
        const navigation = hilosPublicLegalNavigation(page, resolve)
        expect(navigation.page).toBe(page)
        expect(navigation.tabs).toEqual([
          { page: main, label },
          { page: history, label: 'Revision history' },
        ])
        expect(
          hilosTabLinks(navigation.tabs, {}, navigation.resolvePath).map(
            (tab) => tab.to,
          ),
        ).toEqual([path, `${path}/history`])
      }
      const filtered = hilosPublicLegalNavigation(main, (page) =>
        page === main ? '/custom/legal' : undefined,
      )
      expect(
        hilosTabLinks(filtered.tabs, {}, filtered.resolvePath),
      ).toHaveLength(1)
      expect(hilosPublicLegalNavigation('other', resolve).tabs).toEqual([])
    },
  )
})

describe.each(['terms', 'privacy'] as const)(
  '%s public history',
  (document) => {
    it('is inert until start, loads the subscription window, and sends real rendered/viewport frames', () => {
      const w = world(document)
      const before = w.socket.frames()
      expect(w.history.document.get()).toBeUndefined()
      expect(w.history.controller.frame.body.get()).toBe('loading')
      expect(w.connection.tableWindowDescriptors()).toEqual({})
      const stop = w.history.start()
      expect(w.history.start()).toBe(stop)
      expect(w.socket.frames()).toEqual(before)
      w.answer()
      expect(w.history.document.get()).toEqual(w.text)
      expect(
        w.history.controller.rows.get().map((view) => view.row?.revisionId),
      ).toEqual([...w.text.revisions].reverse().map((row) => row.revisionId))
      expect(w.history.controller.frame.body.get()).toBe('rows')
      expect(
        w.socket.frames().filter((frame) => frame.type === 'table_rendered'),
      ).toEqual([
        {
          type: 'table_rendered',
          page: w.page,
          tableKey: w.tableKey,
          rendered: [
            'publishedOn',
            'revisionId',
            'origin',
            'significance',
            'effectiveOn',
            'setVersion',
            'previousSetVersion',
          ],
        },
      ])
      w.history.controller.nextPage()
      expect(w.socket.frames().at(-1)).toMatchObject({
        type: 'table_viewport',
        page: w.page,
        tableKey: w.tableKey,
        anchor: { rowKey: first.revisionId },
        anchorDirection: 'after',
        limit: 25,
      })
      stop()
      expect(w.connection.tableWindowDescriptors()).toEqual({})
      expect(w.history.document.get()).toBeUndefined()
    })

    it('replays a complete first answer delivered before the view mounted, without a second fetch', () => {
      const w = world(document)
      w.answer()
      const before = w.socket
        .frames()
        .filter((frame) => frame.type === 'page_subscribe')
      w.history.start()
      expect(w.history.controller.frame.body.get()).toBe('rows')
      expect(w.history.document.get()).toEqual(w.text)
      expect(
        w.socket.frames().filter((frame) => frame.type === 'page_subscribe'),
      ).toEqual(before)
    })

    it('declares only Revision, Change and Open, with native cards and unpublished empty words', () => {
      const w = world(document)
      const frame = w.history.controller.frame
      expect(
        frame.columns.get().map(({ key, label }) => ({ key, label })),
      ).toEqual([
        { key: 'publishedOn', label: 'Revision' },
        { key: 'origin', label: 'Change' },
        { key: 'actions', label: '' },
      ])
      expect(frame.card.get()).toMatchObject({
        title: { key: 'publishedOn' },
        actions: { key: 'actions' },
      })
      expect(frame.orders.get()).toEqual([])
      expect(frame.declaration).not.toHaveProperty('title')
      expect(frame.declaration).not.toHaveProperty('subtitle')
      expect(frame.declaration).not.toHaveProperty('search')
      expect(frame.declaration).not.toHaveProperty('filters')
      expect(frame.declaration).not.toHaveProperty('mainAction')
      expect(frame.declaration).not.toHaveProperty('bulkActions')
      w.history.start()
      w.answer(null, false)
      expect(frame.body.get()).toBe('empty')
      expect(frame.declaration.empty?.hint).toBe(
        document === 'terms'
          ? 'This project has not published its terms.'
          : 'This project has not published its privacy policy.',
      )
      expect(w.history.document.get()).toBeNull()
      expect(w.history.agreement.get()).toBeNull()
    })

    it('uses the shared revision schema, date and provenance presentation', () => {
      const w = world(document)
      w.history.start()
      w.answer()
      const row = w.history.controller.rows.get().at(-1)!.row!
      expect(row).toMatchObject({
        revisionId: first.revisionId,
        publishedLabel: '17 September 2026',
        changeLabel: 'First revision · Hilos standard 1',
        revisionTitle: `${document === 'terms' ? 'Terms' : 'Privacy'} — revision of 17 September 2026`,
      })
      w.history.controller.ingestSubscriptionWindow(
        [
          {
            rowKey: 'broken',
            slots: {
              revision: {
                ...first,
                origin: 'invented',
                previousSetVersion: null,
              },
            },
          },
        ],
        1,
        true,
        null,
        null,
        25,
        undefined,
        [],
      )
      expect(() => w.history.controller.rows.get()).toThrow()
    })

    it('never paints personal acceptances for a guest, even if a personal section arrives', () => {
      const w = world(document, null)
      w.history.start()
      w.answer()
      expect(w.history.agreement.get()).toBeNull()
      expect(
        w.history.controller.rows.get().every((view) => !view.row?.accepted),
      ).toBe(true)
      expect(
        w.history.controller.rows.get().filter((view) => view.row?.inForce),
      ).toHaveLength(1)
    })

    it('ignores another page answer and releases late reads and frames on explicit cleanup', async () => {
      const w = world(document)
      const stop = w.history.start()
      w.answer(
        null,
        false,
        document === 'terms' ? 'hilos_terms' : 'hilos_privacy',
      )
      expect(w.history.document.get()).toBeUndefined()
      expect(w.history.controller.frame.body.get()).toBe('loading')
      w.answer()
      const pending = w.history.reader.open(first.revisionId)
      stop()
      w.answer()
      w.requests[0]!.resolve({
        reply: { document, revisionId: first.revisionId, clauses: [clause] },
      })
      await pending
      expect(w.history.document.get()).toBeUndefined()
      expect(w.history.agreement.get()).toBeUndefined()
      expect(w.history.controller.rows.get()).toEqual([])
      expect(w.history.reader.dialog.get()).toBeNull()
      expect(w.connection.tableWindowDescriptors()).toEqual({})
    })

    it('opens through its main public page action and closes across identities, navigation and cleanup', async () => {
      const w = world(document)
      w.history.start()
      w.answer()
      const pending = w.history.reader.open(first.revisionId)
      expect(w.requests[0]).toMatchObject({
        name:
          document === 'terms'
            ? 'hilos_terms_revision_text'
            : 'hilos_privacy_revision_text',
        payload: { document, revisionId: first.revisionId },
      })
      w.scopes.session.data.set('currentUser', { type: 'user', id: 2 })
      expect(w.history.reader.dialog.get()).toBeNull()
      expect(w.history.agreement.get()).toBeUndefined()
      expect(
        w.history.controller.rows.get().every((view) => !view.row?.accepted),
      ).toBe(true)
      w.requests[0]!.resolve({
        reply: { document, revisionId: first.revisionId, clauses: [clause] },
      })
      await pending
      expect(w.history.reader.dialog.get()).toBeNull()
      w.answer({ ...w.agreement, accepted: [] })
      const late = w.history.reader.open(first.revisionId)
      w.pages.subscribe('hilos_about')
      expect(w.history.reader.dialog.get()).toBeNull()
      expect(w.history.agreement.get()).toBeUndefined()
      expect(w.history.document.get()).toBeUndefined()
      expect(w.history.controller.rows.get()).toEqual([])
      const after = w.socket.frames().length
      w.answer()
      w.group(w.agreement)
      w.requests[1]!.reject(new Error('Old read refused'))
      await late
      expect(w.history.reader.dialog.get()).toBeNull()
      await w.history.reader.open(first.revisionId)
      expect(w.requests).toHaveLength(2)
      expect(w.socket.frames()).toHaveLength(after)
      w.history.cleanup()
      w.history.cleanup()
    })

    it('keeps the current viewport registered across reconnect and ingests the authoritative window', () => {
      vi.useFakeTimers()
      const w = world(document)
      w.history.start()
      w.answer()
      w.history.controller.nextPage()
      w.socket.emit('close')
      vi.advanceTimersByTime(30_000)
      const socket = w.sockets.at(-1)!
      expect(socket).not.toBe(w.socket)
      socket.emit('open')
      socket.message('handshake', { build: 'test' })
      w.pages.releaseOnSession()
      expect(
        socket.frames().find((frame) => frame.type === 'page_subscribe'),
      ).toMatchObject({
        page: w.page,
        tableWindows: {
          [w.tableKey]: {
            pageIndex: null,
            anchor: { rowKey: first.revisionId },
            anchorDirection: 'after',
            limit: 25,
          },
        },
      })
      w.answer(w.agreement, true, w.page, [w.text.revisions[0]!], socket)
      expect(w.history.controller.rows.get()).toHaveLength(1)
      expect(w.history.document.get()).toEqual(w.text)
      expect(w.history.controller.frame.body.get()).toBe('rows')
    })
  },
)

describe('personal history badges and group refresh', () => {
  it('marks every accepted revision, including a revision other than held, and drops badges on sign-out', () => {
    const w = world()
    w.history.start()
    w.answer()
    expect(
      w.history.controller.rows
        .get()
        .filter((view) => view.row?.accepted)
        .map((view) => view.row?.revisionId),
    ).toEqual([current.revisionId, first.revisionId])
    w.scopes.session.data.set('currentUser', null)
    expect(w.history.agreement.get()).toBeNull()
    expect(
      w.history.controller.rows.get().every((view) => !view.row?.accepted),
    ).toBe(true)
  })

  it('refreshes changed accepted sets, coalesces repeats and retains newer hints until a full answer', () => {
    const w = world()
    w.history.start()
    w.answer()
    const before = w.socket
      .frames()
      .filter((frame) => frame.type === 'page_subscribe').length
    const one = { ...w.agreement, accepted: [w.agreement.accepted[0]!] }
    const none = { ...w.agreement, accepted: [] }
    w.group(one)
    w.group(one)
    w.group(none)
    expect(
      w.socket.frames().filter((frame) => frame.type === 'page_subscribe'),
    ).toHaveLength(before + 1)
    expect(w.history.agreement.get()).toEqual(w.agreement)
    w.answer(one)
    expect(
      w.socket.frames().filter((frame) => frame.type === 'page_subscribe'),
    ).toHaveLength(before + 2)
    w.answer(none)
    expect(
      w.history.controller.rows.get().every((view) => !view.row?.accepted),
    ).toBe(true)
  })

  it('does not refresh a reordered accepted set or an acceptedAt-only change', () => {
    const w = world()
    w.history.start()
    w.answer()
    const before = w.socket.frames().length
    const next = {
      ...w.agreement,
      accepted: [...w.agreement.accepted]
        .reverse()
        .map((item) => ({ ...item, acceptedAt: 3 })),
    }
    w.group(next)
    expect(w.socket.frames()).toHaveLength(before)
    expect(w.history.agreement.get()).toEqual(next)
  })

  it('does not refresh when only the other document changes', () => {
    const w = world()
    w.history.start()
    w.answer()
    const before = w.socket.frames().length
    w.socket.message('hilos_legal_agreements_state', {
      documents: [w.agreement, { ...privacyAgreement, accepted: [] }],
    })
    expect(w.socket.frames()).toHaveLength(before)
    expect(w.history.document.get()).toEqual(w.text)
  })

  it('keeps the old public store default without an accepted-set refresh', () => {
    const w = world()
    const store = createHilosLegalPublicDocumentStore(
      { connection: w.connection, scopes: w.scopes, actions: w.actions },
      {
        document: 'terms',
        page: w.page,
        section: w.section,
        copy: LEGAL_TERMS_COPY,
      },
    )
    const stop = store.start()
    w.answer()
    const before = w.socket.frames().length
    w.group({ ...w.agreement, accepted: [] })
    expect(w.socket.frames()).toHaveLength(before)
    expect(store.agreement.get()?.accepted).toEqual([])
    stop()
  })
})
