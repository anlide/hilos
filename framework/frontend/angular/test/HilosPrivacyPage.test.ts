// The Angular peer of vue/src/public/HilosPrivacyPage.test.ts and
// react/test/HilosPrivacyPage.test.tsx: the same cases by the same names, so a
// drift between the three view layers shows up as one of them failing rather
// than as a page nobody compared. What is Angular's own is the mount (TestBed),
// the fact that a frame is read by running change detection, and that the modal
// renders in place rather than through a portal — one root covers the page and
// its dialog alike.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  bindLegalReconsent,
  bindSessionScope,
  browserValue,
  type ActionResult,
  createSignal,
  HILOS_BROWSER_VALUES,
  type ActionHandle,
  type ActionLifecycle,
  type HilosBrowserValue,
  type HilosConnection,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  legalContext,
  privacyCurrent,
  privacySection,
  privacySectionBehind,
  privacyAgreement,
} from '../../core/test/legal/fixtures.js'

import { HilosPrivacyPage } from '../src/public/HilosPrivacyPage.js'

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
})

/** A handle whose reply is already settled, so a click resolves within the tick. */
function handle(done: Promise<{ message?: string }>): ActionHandle {
  return { requestId: '1', loading: createSignal(false), done }
}

/** The connection the page reads a cookie name from and drops a pass on. */
function fakeConnection(): HilosConnection {
  return {
    ...legalContext({} as ActionLifecycle, { page: 'hilos_privacy', data: {} })
      .context.connection,
    sessionCookieName: 'hilos_session_token',
    forgetProtectedModePass: vi.fn(),
  } as unknown as HilosConnection
}

/** A lifecycle that records the dispatch and answers it however the test says. */
function fakeActions(done: Promise<{ message?: string }>): ActionLifecycle {
  return {
    dispatch: vi.fn(() => handle(done)),
  } as unknown as ActionLifecycle
}

/**
 * Mount the page with the connection and lifecycle a project hands it.
 *
 * @param connection The connection the erase acts on.
 * @param actions The lifecycle the erase is dispatched on.
 * @param values The project's own declarations, if it has any.
 * @returns The mounted fixture, already rendered once.
 */
function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle,
  values: readonly HilosBrowserValue[] = [],
): ComponentFixture<HilosPrivacyPage> {
  const fixture = TestBed.createComponent(HilosPrivacyPage)
  fixture.componentRef.setInput('context', {
    ...legalContext(actions, { page: 'hilos_privacy', data: {} }).context,
    connection,
  })
  fixture.componentRef.setInput('values', values)
  fixture.detectChanges()

  return fixture
}

/**
 * Find a node of the mounted page by its stable test id.
 *
 * @param fixture The mounted page to look inside.
 * @param id The `data-id` the page renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosPrivacyPage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * Open the confirmation and take its dangerous answer, settling the reply.
 *
 * @param fixture The mounted page to click inside.
 */
async function erase(
  fixture: ComponentFixture<HilosPrivacyPage>,
): Promise<void> {
  byId(fixture, 'privacy-erase')?.click()
  fixture.detectChanges()
  byId(fixture, 'privacy-erase-confirm')?.click()
  await fixture.whenStable()
  fixture.detectChanges()
}

/**
 * Let the reply the page awaits arrive under a fake clock, and draw what it
 * changed.
 *
 * @param fixture The mounted page to draw.
 */
async function flush(
  fixture: ComponentFixture<HilosPrivacyPage>,
): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
}

describe('HilosPrivacyPage', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('offers the erase without asking anything of the browser first', () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
    )

    expect(byId(fixture, 'privacy-erase')).not.toBeNull()
    expect(byId(fixture, 'privacy-erase-done')).toBeNull()
    // Never disabled, in any state: the browser half runs offline just as well.
    expect((byId(fixture, 'privacy-erase') as HTMLButtonElement).disabled).toBe(
      false,
    )
  })

  it('names every registry entry in the confirmation, the project’s beside the framework’s', () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
      [
        browserValue({
          store: 'local',
          key: 'demo.theme',
          label: 'The theme this browser was last set to',
        }),
      ],
    )
    byId(fixture, 'privacy-erase')?.click()
    fixture.detectChanges()

    const listed = [
      ...(byId(fixture, 'privacy-erase-list') as HTMLElement).querySelectorAll(
        'li',
      ),
    ].map((item) => item.textContent)

    expect(listed).toHaveLength(HILOS_BROWSER_VALUES.length + 1)
    expect(listed.at(-1)).toBe('The theme this browser was last set to')
  })

  it('sweeps the browser and drops the live pass before the action goes out', async () => {
    const connection = fakeConnection()
    const order: string[] = []
    const actions = {
      dispatch: vi.fn(() => {
        order.push('dispatch')

        return handle(Promise.resolve({}))
      }),
    } as unknown as ActionLifecycle
    vi.mocked(connection.forgetProtectedModePass).mockImplementation(() => {
      order.push('forget')
    })
    sessionStorage.setItem('hilos.oauth.provider', 'github')
    localStorage.setItem('hilos.protectedMode.hint', '1')

    const fixture = mountPage(connection, actions)
    await erase(fixture)

    expect(sessionStorage.getItem('hilos.oauth.provider')).toBeNull()
    expect(localStorage.getItem('hilos.protectedMode.hint')).toBeNull()
    expect(order).toEqual(['forget', 'dispatch'])
  })

  it('becomes the outcome, naming what went and that the account did not', async () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
    )
    await erase(fixture)

    const done = byId(fixture, 'privacy-erase-done')
    expect(done).not.toBeNull()
    expect(byId(fixture, 'privacy-erase')).toBeNull()
    expect(done?.textContent).toContain('a new one begun')
    expect(done?.textContent).toContain('not account deletion')
    expect(byId(fixture, 'privacy-erase-partial')).toBeNull()
  })

  it('tells the truth in parts when the server half refused', async () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(
        Promise.reject(
          new ActionError('hilos_browser_erase', 'fail', 'Session is gone'),
        ),
      ),
    )
    await erase(fixture)

    expect(byId(fixture, 'privacy-erase-partial')?.textContent).toContain(
      'Session is gone',
    )
    // What the browser half did stands: the outcome still names it.
    expect(byId(fixture, 'privacy-erase-done')?.textContent).toContain(
      'were erased',
    )
  })

  it('holds the confirmation from the first moment of the erase, keeps Erase looking live until its spinner, and sends once (HIL-1768)', async () => {
    vi.useFakeTimers()
    const connection = fakeConnection()
    let answer!: (reply: { message?: string }) => void
    const actions = fakeActions(
      new Promise((resolve) => {
        answer = resolve
      }),
    )
    const fixture = mountPage(connection, actions)
    byId(fixture, 'privacy-erase')?.click()
    fixture.detectChanges()
    const confirm = byId(fixture, 'privacy-erase-confirm') as HTMLButtonElement
    const spinner = (): Element | null =>
      confirm.querySelector('[data-id="loading-button-spinner"]')

    confirm.click()
    await flush(fixture)
    expect(actions.dispatch).toHaveBeenCalledOnce()
    expect(
      (byId(fixture, 'privacy-erase-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect((byId(fixture, 'modal-close') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(confirm.disabled).toBe(false)
    expect(confirm.classList.contains('disabled')).toBe(false)
    expect(spinner()).toBeNull()

    confirm.click()
    vi.advanceTimersByTime(299)
    fixture.detectChanges()
    expect(spinner()).toBeNull()
    expect(confirm.classList.contains('disabled')).toBe(false)
    vi.advanceTimersByTime(1)
    fixture.detectChanges()
    expect(spinner()).not.toBeNull()
    expect(confirm.classList.contains('disabled')).toBe(true)
    expect(actions.dispatch).toHaveBeenCalledOnce()
    expect(connection.forgetProtectedModePass).toHaveBeenCalledOnce()

    answer({})
    await flush(fixture)
    expect(byId(fixture, 'privacy-erase-done')).not.toBeNull()
    expect(byId(fixture, 'privacy-erase-confirm')).toBeNull()
  })
})

let unbindPolicy: (() => void) | null = null

afterEach(() => {
  unbindPolicy?.()
  unbindPolicy = null
})

/** Server-authored standing, separate from the policy verdict. */
function policyStanding(frozen = false) {
  return {
    shown: frozen ? 'frozen' : 'none',
    blocked: false,
    frozen,
    deletionEffectiveAt: null,
    lapsed: [],
    window: [],
    mergedInto: null,
    mergedIntoName: null,
  }
}

function policyNode(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

let policyFixture: ComponentFixture<HilosPrivacyPage> | null = null
async function policyUpdate(run: () => void = () => {}): Promise<void> {
  run()
  await Promise.resolve()
  policyFixture!.detectChanges()
  await policyFixture!.whenStable()
  policyFixture!.detectChanges()
}

async function drawPolicy(
  data: Record<string, unknown>,
  reader: { frozen?: boolean; impersonated?: boolean } | null = null,
  dispatch = vi.fn(
    (): {
      done: Promise<ActionResult>
      loading: ReturnType<typeof createSignal<boolean>>
      requestId: string
    } => ({
      done: new Promise(() => {}),
      loading: createSignal(false),
      requestId: 'policy',
    }),
  ),
) {
  const world = legalContext({ dispatch } as unknown as ActionLifecycle, {
    page: 'hilos_privacy',
    data,
  })
  Object.assign(world.context.connection, {
    sessionCookieName: 'hilos_session_token',
    forgetProtectedModePass: vi.fn(),
  })
  bindSessionScope(world.context.connection, world.context.scopes)
  if (reader !== null) {
    unbindPolicy = bindLegalReconsent(
      world.context.scopes,
      world.context.actions,
    )
    world.project('handshake_response', {
      data: { accountStanding: policyStanding(reader.frozen) },
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: reader.impersonated ? { id: 1, name: 'Ada' } : null,
      },
    })
  }

  const view = TestBed.createComponent(HilosPrivacyPage)
  policyFixture = view
  view.componentRef.setInput('context', world.context)
  document.body.appendChild(view.nativeElement as HTMLElement)
  const dispose = () => view.destroy()
  const replace = (context: typeof world.context) =>
    view.componentRef.setInput('context', context)

  await policyUpdate()
  return { world, dispatch, dispose, replace }
}

const POLICY_DUE = {
  legalPrivacy: privacySectionBehind,
  legalAgreements: { documents: [privacyAgreement] },
}
const POLICY_COVERED = {
  legalPrivacy: privacySection,
  legalAgreements: {
    documents: [
      {
        ...privacyAgreement,
        held: privacyCurrent,
        standing: 'covered',
        deadline: null,
      },
    ],
  },
}

it('holds an invisible reader before the policy arrives and leaves erase available', async () => {
  const { world, dispatch } = await drawPolicy({})
  expect(policyNode('privacy-loading')!.textContent?.trim()).toBe(
    'Loading the privacy policy…',
  )
  expect(
    policyNode('legal-document-reader-line')!.getAttribute('aria-hidden'),
  ).toBe('true')
  expect(
    policyNode('legal-document-reader-line')!.classList.contains('invisible'),
  ).toBe(true)
  expect(policyNode('privacy-erase')).not.toBeNull()
  expect(dispatch).not.toHaveBeenCalled()
  expect(world.frames).toEqual([])
})

it('reads the public policy without acceptance or a history interface', async () => {
  await drawPolicy({ legalPrivacy: privacySection })
  expect(policyNode('static-page-title')!.textContent?.trim()).toBe('Privacy')
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe('guest')
  expect(policyNode('privacy-reader')!.textContent).toContain(
    'in force since 14 November 2026',
  )
  expect(policyNode('privacy-text')!.textContent).toContain('First paragraph.')
  expect(policyNode('privacy-text')!.querySelectorAll('li')).toHaveLength(1)
  expect(policyNode('legal-document-accept')).toBeNull()
  expect(policyNode('privacy-history')).toBeNull()
  expect(
    policyNode('privacy-text')!.compareDocumentPosition(
      policyNode('privacy-erase')!,
    ) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy()
})

it('keeps erase and the introduction available without a published policy', async () => {
  await drawPolicy({ legalPrivacy: null })
  expect(policyNode('privacy-none')!.textContent?.trim()).toBe(
    'This project has not published its privacy policy.',
  )
  expect(policyNode('privacy-reader')).toBeNull()
  expect(policyNode('privacy-text')).toBeNull()
  expect(policyNode('privacy-erase')).not.toBeNull()
})

it('shows no acceptance path for a signed-in reader with no record', async () => {
  await drawPolicy(
    {
      legalPrivacy: privacySection,
      legalAgreements: {
        documents: [
          {
            ...privacyAgreement,
            held: null,
            standing: 'none',
            accepted: [],
            acceptedAt: null,
            deadline: null,
          },
        ],
      },
    },
    {},
  )
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe(
    'unrecorded',
  )
  expect(policyNode('privacy-reader')!.textContent).toContain(
    'No acceptance of yours is on record.',
  )
  expect(policyNode('legal-document-accept')).toBeNull()
})

it('confirms server coverage in green', async () => {
  await drawPolicy(POLICY_COVERED, {})
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe(
    'covered',
  )
  expect(
    policyNode('privacy-reader')!.querySelector('.text-success'),
  ).not.toBeNull()
  expect(policyNode('privacy-reader')!.textContent).toContain(
    'You accepted the revision of 5 October 2026',
  )
  expect(policyNode('legal-document-accept')).toBeNull()
})

it('compares editorial changes with Close alone and returns focus', async () => {
  await drawPolicy(
    {
      ...POLICY_DUE,
      legalAgreements: {
        documents: [
          { ...privacyAgreement, standing: 'covered', deadline: null },
        ],
      },
    },
    {},
  )
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe(
    'reworded',
  )
  const trigger = policyNode('legal-document-changes-open')!
  trigger.focus()
  await policyUpdate(() => trigger.click())
  expect(policyNode('privacy-changes-modal')!.textContent).toContain(
    'Old wording',
  )
  const dialog = policyNode('privacy-changes-modal')!.closest(
    '[role="dialog"]',
  )!
  expect(dialog.getAttribute('aria-modal')).toBe('true')
  expect(dialog.textContent).toContain(
    'What changed · 17 September 2026 → 5 October 2026',
  )
  expect(policyNode('privacy-changes-accept')).toBeNull()
  await policyUpdate(() => policyNode('privacy-changes-close')!.click())
  expect(policyNode('privacy-changes-modal')).toBeNull()
  expect(document.activeElement).toBe(trigger)
})

it('accepts only the shown policy from the shared reader, once, without optimistic coverage', async () => {
  const { dispatch } = await drawPolicy(POLICY_DUE, {})
  expect(policyNode('legal-document-reader-plate')!.textContent).toContain(
    '14 November 2026',
  )
  expect(policyNode('legal-document-accept-refusal-idle')).not.toBeNull()
  await policyUpdate(() => {
    policyNode('legal-document-accept')!.click()
    policyNode('legal-document-accept')!.click()
  })
  expect(dispatch).toHaveBeenCalledExactlyOnceWith('hilos_legal_accept', {
    acceptedRevisions: { privacy: '2026-10-05' },
  })
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe('due')
  expect(policyNode('legal-document-accept')!.getAttribute('aria-busy')).toBe(
    'true',
  )
})

it('shows a frozen deadline and permits comparison under impersonation without acceptance', async () => {
  await drawPolicy(
    {
      ...POLICY_DUE,
      legalAgreements: {
        documents: [
          { ...privacyAgreement, standing: 'lapsed', deadline: '2026-10-01' },
        ],
      },
    },
    { frozen: true, impersonated: true },
  )
  expect(policyNode('legal-document-reader-plate')!.textContent).toContain(
    'account frozen · deadline passed 1 October 2026',
  )
  expect(
    policyNode('legal-document-reader-impersonated')!.textContent?.trim(),
  ).toBe('Only Bob can accept the privacy policy.')
  expect(policyNode('legal-document-accept')).toBeNull()
  await policyUpdate(() => policyNode('legal-document-changes-open')!.click())
  expect(policyNode('privacy-changes-modal')).not.toBeNull()
  expect(policyNode('privacy-changes-accept')).toBeNull()
})

it('holds every comparison close gesture while accepting and closes on success before server coverage', async () => {
  let resolve!: (value: ActionResult) => void
  const { world, dispatch } = await drawPolicy(
    POLICY_DUE,
    {},
    vi.fn(() => ({
      requestId: 'policy',
      loading: createSignal(false),
      done: new Promise<ActionResult>((done) => {
        resolve = done
      }),
    })),
  )
  await policyUpdate(() => policyNode('legal-document-changes-open')!.click())
  await policyUpdate(() => policyNode('privacy-changes-accept')!.click())
  expect(policyNode('privacy-changes-accept')!.getAttribute('aria-busy')).toBe(
    'true',
  )
  expect(policyNode('legal-document-accept')!.getAttribute('aria-busy')).toBe(
    'true',
  )
  expect(
    (policyNode('privacy-changes-close') as HTMLButtonElement).disabled,
  ).toBe(true)
  expect((policyNode('modal-close') as HTMLButtonElement).disabled).toBe(true)
  await policyUpdate(() => {
    policyNode('privacy-changes-close')!.click()
    policyNode('modal-close')!.click()
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    policyNode('privacy-changes-accept')!.click()
  })
  expect(policyNode('privacy-changes-modal')).not.toBeNull()
  expect(dispatch).toHaveBeenCalledTimes(1)
  await policyUpdate(() => resolve({}))
  expect(policyNode('privacy-changes-modal')).toBeNull()
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe('due')
  expect(world.frames).toContainEqual({
    type: 'page_subscribe',
    page: 'hilos_privacy',
    params: {},
  })
  await policyUpdate(() => world.answer(POLICY_COVERED))
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe(
    'covered',
  )
})

it('keeps a refused acceptance in the comparison body and reserves its room', async () => {
  let reject!: (reason: unknown) => void
  const { world } = await drawPolicy(
    POLICY_DUE,
    {},
    vi.fn(() => ({
      requestId: 'policy',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, fail) => {
        reject = fail
      }),
    })),
  )
  await policyUpdate(() => policyNode('legal-document-changes-open')!.click())
  const slot = policyNode('privacy-changes-refusal-slot')!
  expect(slot.textContent?.trim()).toBe('')
  expect(slot.closest('.modal-body')).not.toBeNull()
  await policyUpdate(() => policyNode('privacy-changes-accept')!.click())
  await policyUpdate(() =>
    reject(
      new ActionError('hilos_legal_accept', 'fail', 'This policy has changed.'),
    ),
  )
  expect(policyNode('privacy-changes-modal')).not.toBeNull()
  expect(slot.textContent).toContain('This policy has changed.')
  expect(policyNode('legal-document-accept-refusal')).toBeNull()
  await policyUpdate(() => world.answer(POLICY_DUE))
  expect(slot.textContent).toContain('This policy has changed.')
  await policyUpdate(() => policyNode('privacy-changes-close')!.click())
  expect(policyNode('legal-document-accept-refusal')!.textContent).toContain(
    'This policy has changed.',
  )
})

it('clears personal comparison on identity departure and releases listeners on unmount', async () => {
  const { world, dispose } = await drawPolicy(POLICY_DUE, {})
  const live = world.listenerCount()
  await policyUpdate(() => policyNode('legal-document-changes-open')!.click())
  await policyUpdate(() =>
    world.context.scopes.session.data.set('currentUser', {
      type: 'user',
      id: 3,
    }),
  )
  expect(policyNode('privacy-reader')!.getAttribute('data-state')).toBe(
    'loading',
  )
  expect(policyNode('privacy-changes-modal')).toBeNull()
  expect(policyNode('legal-document-changes-open')).toBeNull()
  dispose()
  expect(world.listenerCount()).toBeLessThan(live)
})

it('releases the old context and does not carry its comparison into another one', async () => {
  const { world, replace, dispose } = await drawPolicy(POLICY_DUE, {})
  await policyUpdate(() => policyNode('legal-document-changes-open')!.click())
  const before = world.listenerCount()
  const next = legalContext(
    { dispatch: vi.fn() } as unknown as ActionLifecycle,
    { page: 'hilos_privacy', data: { legalPrivacy: null } },
  )
  await policyUpdate(() => replace(next.context))
  expect(world.listenerCount()).toBeLessThan(before)
  expect(policyNode('privacy-changes-modal')).toBeNull()
  expect(policyNode('privacy-none')).not.toBeNull()
  dispose()
  expect(next.listenerCount()).toBe(0)
})

it.each([
  ['success', 'alert-success'],
  ['partial', 'alert-warning'],
] as const)(
  'keeps the %s erase outcome through the new guest session independently of policy coverage',
  async (outcome, color) => {
    let resolve!: (value: ActionResult) => void
    let reject!: (reason: unknown) => void
    const { world } = await drawPolicy(
      POLICY_COVERED,
      {},
      vi.fn(() => ({
        requestId: 'erase',
        loading: createSignal(false),
        done: new Promise<ActionResult>((done, fail) => {
          resolve = done
          reject = fail
        }),
      })),
    )
    const live = policyNode('privacy-erase')!
      .closest('section')!
      .querySelector('[role="status"]')!
    expect(live.getAttribute('aria-live')).toBe('polite')
    expect(live.textContent?.trim()).toBe('')
    await policyUpdate(() => policyNode('privacy-erase')!.click())
    await policyUpdate(() => policyNode('privacy-erase-confirm')!.click())
    expect(policyNode('privacy-erase-done')).toBeNull()
    await policyUpdate(() => {
      if (outcome === 'success') resolve({})
      else
        reject(
          new ActionError('hilos_browser_erase', 'fail', 'Session is gone'),
        )
    })
    const done = policyNode('privacy-erase-done')!
    expect(live.contains(done)).toBe(true)
    expect(done.classList.contains(color)).toBe(true)
    expect(done.textContent).toContain('Nothing about your account changed.')
    if (outcome === 'partial') {
      expect(policyNode('privacy-erase-partial')!.textContent).toContain(
        'Session is gone',
      )
      expect(done.textContent).not.toContain('a new one begun')
    }
    const content = done.textContent
    await policyUpdate(() =>
      world.context.scopes.session.data.set('currentUser', null),
    )
    await policyUpdate(() => world.answer({ legalPrivacy: null }))
    expect(policyNode('privacy-reader')).toBeNull()
    expect(policyNode('privacy-erase-done')).toBe(done)
    expect(done.classList.contains(color)).toBe(true)
    expect(done.textContent).toBe(content)
  },
)
