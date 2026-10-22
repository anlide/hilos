import type {
  Expect,
  Page,
  Request,
  Route,
  WebSocketRoute,
} from '@playwright/test'
import type {
  BudgetedInitialBundleReport,
  InitialBundleSection,
} from '../core/bin/initial-bundle-budget.mjs'
import {
  createSocketFrameReader,
  isApplicationSocket,
  watchSocketFrames,
  type SocketFrame,
} from './socket.js'

export type ClientBundleReport = BudgetedInitialBundleReport
export type LazyBundleSection = ClientBundleReport['lazy'][number]

/** One actual JS/CSS request, retaining duplicates and unsuccessful requests. */
export interface ClientBundleRequest {
  fileName: string
  url: string
  outcome: 'pending' | 'finished' | 'failed'
  failure: string | null
}

/** JSON is a boundary: absent or malformed evidence must fail, never look clean. */
function object(value: unknown): Record<string, unknown> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('Malformed client bundle report: expected an object.')
  }
  return value as Record<string, unknown>
}

function strings(value: unknown): asserts value is string[] {
  if (
    !Array.isArray(value) ||
    !value.every((item) => typeof item === 'string')
  ) {
    throw new Error('Malformed client bundle report: expected strings.')
  }
}

function bytes(value: unknown): asserts value is number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0) {
    throw new Error('Malformed client bundle report: expected byte count.')
  }
}

function section(value: unknown): void {
  const data = object(value)
  for (const key of ['totalBytes', 'jsBytes', 'cssBytes', 'gzipBytes'])
    bytes(data[key])
  strings(data.modules)
  if (!Array.isArray(data.chunks) || !Array.isArray(data.forbiddenModules)) {
    throw new Error(
      'Malformed client bundle report: missing chunks or attribution verdict.',
    )
  }
  const files = new Set<string>()
  let total = 0
  let js = 0
  let css = 0
  let gzip = 0
  for (const value of data.chunks) {
    const chunk = object(value)
    if (
      typeof chunk.fileName !== 'string' ||
      !/\.(js|css)$/.test(chunk.fileName)
    ) {
      throw new Error(
        'Malformed client bundle report: expected an emitted JS/CSS filename.',
      )
    }
    if (files.has(chunk.fileName))
      throw new Error('Malformed client bundle report: repeated file.')
    files.add(chunk.fileName)
    bytes(chunk.bytes)
    bytes(chunk.gzipBytes)
    total += chunk.bytes
    gzip += chunk.gzipBytes
    if (chunk.fileName.endsWith('.js')) js += chunk.bytes
    else css += chunk.bytes
    strings(chunk.imports)
    strings(chunk.dynamicImports)
    strings(chunk.modules)
    if (chunk.fileName.endsWith('.js') && chunk.modules.length === 0) {
      throw new Error(
        'Malformed client bundle report: JS has no module evidence.',
      )
    }
  }
  if (
    total !== data.totalBytes ||
    js !== data.jsBytes ||
    css !== data.cssBytes ||
    gzip !== data.gzipBytes
  ) {
    throw new Error('Malformed client bundle report: inconsistent byte totals.')
  }
  for (const value of data.forbiddenModules) {
    const forbidden = object(value)
    if (
      typeof forbidden.id !== 'string' ||
      typeof forbidden.reason !== 'string'
    ) {
      throw new Error(
        'Malformed client bundle report: invalid attribution refusal.',
      )
    }
  }
}

/**
 * Fetch the report from the same production origin the browser will open.
 *
 * @param page The page whose production origin serves the report.
 */
export async function readClientBundleReport(
  page: Page,
): Promise<ClientBundleReport> {
  const response = await page.request.get('/initial-bundle-report.json')
  if (!response.ok()) throw new Error('Client bundle report is unavailable.')
  const raw: unknown = await response.json()
  const report = object(raw)
  if (report.version !== 1 || !Array.isArray(report.lazy)) {
    throw new Error('Malformed client bundle report: unsupported graph.')
  }
  strings(report.roots)
  if (report.roots.length === 0)
    throw new Error('Client bundle report has no roots.')
  section(report.initial)
  const initial = report.initial as InitialBundleSection
  const initialFiles = new Set(bundleFiles(initial))
  if (
    initial.chunks.length === 0 ||
    initial.modules.length === 0 ||
    report.roots.some((root) => !initialFiles.has(root))
  ) {
    throw new Error('Malformed client bundle report: missing initial closure.')
  }
  for (const value of report.lazy) {
    section(value)
    const lazy = object(value)
    if (
      typeof lazy.entry !== 'string' ||
      !(lazy.chunks as Record<string, unknown>[]).some(
        (chunk) => chunk.fileName === lazy.entry,
      )
    ) {
      throw new Error('Malformed client bundle report: missing lazy entry.')
    }
  }
  const sections = [initial, ...(report.lazy as InitialBundleSection[])]
  const allFiles = new Set(sections.flatMap(bundleFiles))
  for (const group of sections) {
    const closure = new Set([...initialFiles, ...bundleFiles(group)])
    for (const chunk of group.chunks) {
      if (
        chunk.imports.some((file) => !closure.has(file)) ||
        chunk.dynamicImports.some((file) => !allFiles.has(file))
      ) {
        throw new Error(
          'Malformed client bundle report: incomplete emitted closure.',
        )
      }
    }
  }
  const budget = object(report.budget)
  bytes(budget.warningBytes)
  bytes(budget.errorBytes)
  strings(budget.errors)
  strings(budget.warnings)
  return raw as ClientBundleReport
}

/**
 * Find the dynamic entry that owns a source module, rather than guessing a hash.
 *
 * @param report The validated emitted graph.
 * @param moduleSuffix The source-module suffix owned by the selected entry.
 */
export function selectLazyBundle(
  report: ClientBundleReport,
  moduleSuffix: string,
): LazyBundleSection {
  const matches = report.lazy.filter((entry) =>
    entry.chunks.some(
      (chunk) =>
        chunk.fileName === entry.entry &&
        chunk.modules.some((id) => id.endsWith(moduleSuffix)),
    ),
  )
  if (matches.length !== 1) {
    throw new Error(
      `Expected one lazy entry owning ${moduleSuffix}; found ${matches.length}.`,
    )
  }
  return matches[0]!
}

/**
 * Static closure only; the report already excludes the initial closure.
 *
 * @param section The initial or lazy static closure to read.
 */
export function bundleFiles(section: InitialBundleSection): string[] {
  return section.chunks.map((chunk) => chunk.fileName)
}

/**
 * Shared dependencies are allowed; only files exclusive to the sibling are forbidden.
 *
 * @param report The validated emitted graph.
 * @param selected The selected section and its static dependencies.
 * @param sibling The sibling whose exclusive files must stay unrequested.
 */
export function siblingExclusiveFiles(
  report: ClientBundleReport,
  selected: LazyBundleSection,
  sibling: LazyBundleSection,
): string[] {
  const allowed = new Set([
    ...bundleFiles(report.initial),
    ...bundleFiles(selected),
  ])
  return bundleFiles(sibling).filter((file) => !allowed.has(file))
}

/**
 * Fail if attribution, budget, or source evidence says the initial bundle is unsafe.
 *
 * @param expect The calling spec's assertion instance.
 * @param report The validated production report.
 */
export function expectPublicBundle(
  expect: Expect,
  report: ClientBundleReport,
): void {
  expect(report.budget.errors).toEqual([])
  expect(report.initial.forbiddenModules).toEqual([])
  expect(report.initial.totalBytes).toBeLessThanOrEqual(5_000_000)
  expect(
    report.initial.modules.filter((id) =>
      /\/admin\/[^/]+\/Hilos[^/]+Page\./.test(id),
    ),
  ).toEqual([])
}

/**
 * Observe before a cold document. Counts include CSS, preload requests and failures.
 *
 * @param page The page to observe before its cold navigation.
 */
export function watchClientBundles(page: Page) {
  const requests: ClientBundleRequest[] = []
  const records = new Map<Request, ClientBundleRequest>()
  const onRequest = (request: Request): void => {
    const url = new URL(request.url())
    if (!/\.(js|css)$/.test(url.pathname)) return
    const record: ClientBundleRequest = {
      fileName: url.pathname.replace(/^\//, ''),
      url: request.url(),
      outcome: 'pending',
      failure: null,
    }
    records.set(request, record)
    requests.push(record)
  }
  const onFinished = (request: Request): void => {
    const record = records.get(request)
    if (record) record.outcome = 'finished'
  }
  const onFailed = (request: Request): void => {
    const record = records.get(request)
    if (record) {
      record.outcome = 'failed'
      record.failure = request.failure()?.errorText ?? null
    }
  }
  page.on('request', onRequest)
  page.on('requestfinished', onFinished)
  page.on('requestfailed', onFailed)
  return {
    requests,
    dispose: (): void => {
      page.off('request', onRequest)
      page.off('requestfinished', onFinished)
      page.off('requestfailed', onFailed)
    },
  }
}

/**
 * Require every observed request to belong to the allowed static closures.
 *
 * @param expect The calling spec's assertion instance.
 * @param requests The actual requests, including duplicates and failures.
 * @param allowedFiles The union of allowed static closures.
 */
export function expectBundleRequests(
  expect: Expect,
  requests: ClientBundleRequest[],
  allowedFiles: string[],
): void {
  const allowed = new Set(allowedFiles)
  expect(requests.filter((request) => !allowed.has(request.fileName))).toEqual(
    [],
  )
}

export interface HeldClientBundle {
  /** Resolves only when this exact emitted file was intercepted. */
  requested: Promise<Request>
  /** Opens the gate for existing and future matching requests. */
  release: (outcome?: 'success' | 'failure') => void
  /** Opens the gate, settles active handlers, and removes just this route. */
  dispose: () => Promise<void>
}

/**
 * Park a real request; always dispose in finally, including when an assertion fails.
 *
 * @param page The page whose emitted request is intercepted.
 * @param fileName An exact emitted filename selected from the report.
 */
export async function holdClientBundle(
  page: Page,
  fileName: string,
): Promise<HeldClientBundle> {
  let observed!: (request: Request) => void
  const requested = new Promise<Request>((resolve) => {
    observed = resolve
  })
  let open!: () => void
  const gate = new Promise<void>((resolve) => {
    open = resolve
  })
  let outcome: 'success' | 'failure' = 'success'
  const active = new Set<Promise<void>>()
  const matches = (url: URL): boolean => url.pathname === `/${fileName}`
  const handler = async (route: Route): Promise<void> => {
    const operation = (async () => {
      observed(route.request())
      await gate
      if (outcome === 'failure') await route.abort('failed')
      else await route.continue()
    })()
    active.add(operation)
    try {
      await operation
    } finally {
      active.delete(operation)
    }
  }
  await page.route(matches, handler)
  return {
    requested,
    release: (next = 'success'): void => {
      outcome = next
      open()
    },
    dispose: async (): Promise<void> => {
      open()
      await Promise.all(active)
      await page.unroute(matches, handler)
    },
  }
}

/**
 * Arm before releasing a held request; overloads stay typed for success and failure.
 *
 * @param page The page whose request will finish or fail.
 * @param fileName The emitted filename of that request.
 * @param outcome The terminal request event expected after release.
 */
export function waitClientBundle(
  page: Page,
  fileName: string,
  outcome: 'success' | 'failure',
): Promise<Request> {
  const predicate = (request: Request): boolean =>
    new URL(request.url()).pathname === `/${fileName}`
  return outcome === 'success'
    ? page.waitForEvent('requestfinished', { predicate })
    : page.waitForEvent('requestfailed', { predicate })
}

/** Subscription facts observed on a real socket, with socket identity and order. */
export interface PageReply {
  socket: number
  type: string
  page: string
  httpCode?: number
}

function pageReply(frame: SocketFrame): PageReply | null {
  if (frame.raw === undefined) return null
  if (frame.wire === undefined) {
    if (/page_response|subscription_page_error/.test(frame.raw)) {
      throw new Error('Malformed page reply in browser evidence.')
    }
    return null
  }
  const wire = object(frame.wire)
  if (wire.type !== 'page_response' && wire.type !== 'subscription_page_error')
    return null
  const data = object(wire.data)
  if (typeof data.page !== 'string')
    throw new Error('Page reply has no page key.')
  return {
    socket: frame.socket,
    type: wire.type,
    page: data.page,
    ...(typeof data.httpCode === 'number' ? { httpCode: data.httpCode } : {}),
  }
}

/**
 * Arm before navigation; a reconnect is proved by a new socket plus its reply.
 *
 * @param page The page to observe before it opens its socket.
 */
export function watchPageReplies(page: Page) {
  const replies: PageReply[] = []
  const observer = watchSocketFrames(page, (frame) => {
    const reply = pageReply(frame)
    if (reply) replies.push(reply)
  })
  return { replies, sockets: observer.sockets }
}

/**
 * Hold the real server's selected page reply before delivering it. No reply is
 * fabricated. Install on a fresh page; WebSocket routing lives until that page
 * closes, while dispose releases all buffered messages and makes it passthrough.
 *
 * @param page A page about to open a fresh socket.
 * @param pageKey The existing subscription key whose real reply is held.
 */
export async function holdPageReply(page: Page, pageKey: string) {
  let open = false
  let observed!: () => void
  const received = new Promise<void>((resolve) => {
    observed = resolve
  })
  const buffered: { client: WebSocketRoute; message: string | Buffer }[] = []
  const replies: PageReply[] = []
  let sockets = 0
  await page.routeWebSocket(isApplicationSocket, (client) => {
    const server = client.connectToServer()
    const read = createSocketFrameReader(++sockets)
    let holding = false
    server.onMessage((message) => {
      const incoming = read(message).flatMap((frame) => {
        const reply = pageReply(frame)
        return reply ? [reply] : []
      })
      replies.push(...incoming)
      if (
        !open &&
        (holding || incoming.some((reply) => reply.page === pageKey))
      ) {
        holding = true
        buffered.push({ client, message })
        observed()
      } else client.send(message)
    })
  })
  const release = (): void => {
    open = true
    for (const { client, message } of buffered.splice(0)) client.send(message)
  }
  return { received, replies, release, dispose: release }
}

export interface PagePresentationSnapshot {
  state: string | null
  departure: boolean
  inert: boolean
  hidden: boolean
  copiedIds: number
  skeleton: boolean
}

/**
 * Browser DOM facts captured before the action, including a short lived copy.
 *
 * @param page The settled page to observe before the triggering action.
 */
export async function watchPagePresentation(page: Page) {
  const handle = await page.evaluateHandle(() => {
    const snapshots: PagePresentationSnapshot[] = []
    const read = (): void => {
      const departure = document.querySelector(
        '[data-id="hilos-page-departure"]',
      )
      snapshots.push({
        state:
          document
            .querySelector('[data-id="hilos-page-state"]')
            ?.getAttribute('data-state') ?? null,
        departure: (departure?.childElementCount ?? 0) > 0,
        inert: departure?.hasAttribute('inert') ?? false,
        hidden: departure?.getAttribute('aria-hidden') === 'true',
        copiedIds: departure?.querySelectorAll('[data-id]').length ?? 0,
        skeleton:
          document.querySelector('[data-id="hilos-page-skeleton"]') !== null,
      })
    }
    const observer = new MutationObserver(read)
    observer.observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['data-state'],
    })
    return { snapshots, stop: () => observer.disconnect() }
  })
  return {
    read: () => handle.evaluate((log) => log.snapshots),
    dispose: async (): Promise<void> => {
      await handle.evaluate((log) => log.stop())
      await handle.dispose()
    },
  }
}

/**
 * A document bookmark proves home/error recovery stays in the same SPA document.
 *
 * @param page The page whose current document must survive an SPA action.
 */
export async function watchClientDocument(page: Page) {
  const document = await page.evaluateHandle(() => window.document)
  return {
    unchanged: async (): Promise<void> => {
      if (
        !(await document.evaluate((original) => original === window.document))
      ) {
        throw new Error('The browser replaced the marked document.')
      }
    },
    dispose: () => document.dispose(),
  }
}

/**
 * The shared safe fallback and completed local-error state across all SDKs.
 *
 * @param expect The calling spec's assertion instance.
 * @param page The page after a proven import failure.
 * @param failedFile The emitted filename that must never be shown to the visitor.
 */
export async function expectLazyPageFailure(
  expect: Expect,
  page: Page,
  failedFile: string,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'error',
  )
  const error = page.getByTestId('page-error')
  await expect(error).toHaveAttribute('data-error-code', '503')
  await expect(error).toContainText('Page could not be loaded')
  await expect(error).toContainText('Please try opening this page again.')
  await expect(error).not.toContainText(failedFile)
  await expect(error).not.toContainText(
    /https?:|TypeError|Error:|import\(|stack|Failed to fetch|dynamically imported/,
  )
  await expect(page.getByTestId('hilos-page-skeleton')).toHaveCount(0)
}

/**
 * A real access refusal must survive pending, rejected and late successful imports.
 *
 * @param expect The calling spec's assertion instance.
 * @param page The page whose real subscription was refused.
 * @param status The observed access verdict: guest or non-admin.
 */
export async function expectServerRefusal(
  expect: Expect,
  page: Page,
  status: 401 | 403,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'error',
  )
  if (status === 401) {
    await expect(page.getByTestId('auth-surface')).toBeVisible()
    await expect(page.getByTestId('page-error')).toHaveCount(0)
  } else {
    await expect(page.getByTestId('page-error')).toHaveAttribute(
      'data-error-code',
      '403',
    )
    await expect(page.getByTestId('page-error')).toContainText('Forbidden')
  }
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-skeleton')).toHaveCount(0)
}

/**
 * Check the framework admin shell and its real context-bound browser table.
 *
 * @param expect The calling spec's assertion instance.
 * @param page The page with its lazy admin implementation mounted.
 * @param title The section title expected in the shared admin head.
 */
export async function expectLazyAdminPage(
  expect: Expect,
  page: Page,
  title: string,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(title)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

/**
 * Before permission or code is ready, no live admin implementation may mount.
 *
 * @param expect The calling spec's assertion instance.
 * @param page The page with a proven code or permission wait.
 */
export async function expectLazyAdminPending(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'loading',
  )
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
}

/**
 * Wait for observed closure requests and the browser's subsequent presentation turn.
 *
 * @param expect The calling spec's assertion instance.
 * @param page The page that will paint the result.
 * @param requests The actual JS/CSS request log.
 * @param selected The section whose observed requests must settle.
 */
export async function settleClientBundles(
  expect: Expect,
  page: Page,
  requests: ClientBundleRequest[],
  selected: LazyBundleSection,
): Promise<void> {
  const files = new Set(bundleFiles(selected))
  await expect
    .poll(
      () =>
        requests.filter(
          (request) =>
            files.has(request.fileName) && request.outcome === 'pending',
        ).length,
    )
    .toBe(0)
  await nextClientPaint(page)
}

/**
 * Flush Promise/render callbacks after the observed network or server condition.
 *
 * @param page The page whose Promise and render callbacks must run.
 */
export async function nextClientPaint(page: Page): Promise<void> {
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
  )
}

/**
 * Check the observed copy → skeleton sequence without a wall-clock timer assertion.
 *
 * @param expect The calling spec's assertion instance.
 * @param snapshots DOM facts recorded before navigation through the held-code wait.
 */
export function expectLazyDeparture(
  expect: Expect,
  snapshots: PagePresentationSnapshot[],
): void {
  const copy = snapshots.findIndex(
    (state) =>
      state.departure && state.inert && state.hidden && state.copiedIds === 0,
  )
  const skeleton = snapshots.findIndex(
    (state) => state.skeleton && !state.departure,
  )
  expect(copy).toBeGreaterThanOrEqual(0)
  expect(skeleton).toBeGreaterThan(copy)
  const loading = snapshots.findIndex((state) => state.state === 'loading')
  expect(loading).toBeGreaterThanOrEqual(0)
  expect(
    snapshots.slice(loading).some((state) => state.state === 'ready'),
  ).toBe(false)
}
