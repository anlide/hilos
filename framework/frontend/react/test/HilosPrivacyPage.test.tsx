import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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
  type HilosConnection,
} from '@hilos/core'
import type { HilosStaticPageNavigation } from '@hilos/core/base'

import {
  legalContext,
  privacyCurrent,
  privacySection,
  privacySectionBehind,
  privacyAgreement,
} from '../../core/test/legal/fixtures.js'

import { HilosPrivacyPage } from '../src/public/HilosPrivacyPage.js'

// The React peer of vue/src/public/HilosPrivacyPage.test.ts: the same cases by
// the same names, so a drift between the two view layers shows up as one of them
// failing rather than as a page nobody compared. The modal portals to <body>, so
// its assertions query the document rather than the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
  vi.useRealTimers()
})

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

function eraseContext(connection: HilosConnection, actions: ActionLifecycle) {
  return {
    ...legalContext(actions, { page: 'hilos_privacy', data: {} }).context,
    connection,
  }
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function publicNavigation(
  page = 'fixture_public_page',
  project = 'first',
  label = 'Public pages',
): HilosStaticPageNavigation {
  return {
    page,
    tabs: [
      { page: 'fixture_public_page', label: 'Public page' },
      { page: 'fixture_history', label: 'Revision history' },
    ],
    params: { project },
    resolvePath: (target, params = {}) =>
      `/projects/${params.project}/${target}`,
    label,
  }
}

/** Opens the confirmation and takes its dangerous answer, settling the reply. */
async function erase(): Promise<void> {
  fireEvent.click(byId('privacy-erase') as HTMLElement)
  await act(async () => {
    fireEvent.click(byId('privacy-erase-confirm') as HTMLElement)
  })
}

describe('HilosPrivacyPage', () => {
  it('offers the erase without asking anything of the browser first', () => {
    render(
      <HilosPrivacyPage
        context={eraseContext(
          fakeConnection(),
          fakeActions(Promise.resolve({})),
        )}
      />,
    )

    expect(byId('privacy-erase')).not.toBeNull()
    expect(byId('privacy-erase-done')).toBeNull()
    // Never disabled, in any state: the browser half runs offline just as well.
    expect((byId('privacy-erase') as HTMLButtonElement).disabled).toBe(false)
  })

  it('names every registry entry in the confirmation, the project’s beside the framework’s', () => {
    render(
      <HilosPrivacyPage
        context={eraseContext(
          fakeConnection(),
          fakeActions(Promise.resolve({})),
        )}
        values={[
          browserValue({
            store: 'local',
            key: 'demo.theme',
            label: 'The theme this browser was last set to',
          }),
        ]}
      />,
    )
    fireEvent.click(byId('privacy-erase') as HTMLElement)

    const listed = [
      ...document.querySelectorAll('[data-id="privacy-erase-list"] li'),
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

    render(<HilosPrivacyPage context={eraseContext(connection, actions)} />)
    await erase()

    expect(sessionStorage.getItem('hilos.oauth.provider')).toBeNull()
    expect(localStorage.getItem('hilos.protectedMode.hint')).toBeNull()
    expect(order).toEqual(['forget', 'dispatch'])
  })

  it('holds the confirmation from the first moment of the erase, keeps Erase looking live until its spinner, and sends once (HIL-1767)', async () => {
    vi.useFakeTimers()
    const connection = fakeConnection()
    let answer!: (reply: { message?: string }) => void
    const actions = fakeActions(
      new Promise((resolve) => {
        answer = resolve
      }),
    )
    render(<HilosPrivacyPage context={eraseContext(connection, actions)} />)
    fireEvent.click(byId('privacy-erase') as HTMLElement)
    const confirm = byId('privacy-erase-confirm') as HTMLButtonElement
    const spinner = (): Element | null =>
      confirm.querySelector('[data-id="loading-button-spinner"]')
    const list = byId('privacy-erase-list')!
    const body = list.closest('fieldset')!
    const labels = list.textContent
    expect(body.disabled).toBe(false)

    await act(async () => {
      fireEvent.click(confirm)
    })
    expect(actions.dispatch).toHaveBeenCalledOnce()
    expect((byId('privacy-erase-cancel') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect((byId('modal-close') as HTMLButtonElement).disabled).toBe(true)
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(confirm.disabled).toBe(false)
    expect(confirm.matches(':disabled')).toBe(false)
    expect(confirm.classList.contains('disabled')).toBe(false)
    expect(spinner()).toBeNull()
    expect(byId('privacy-erase-list')).toBe(list)
    expect(list.closest('fieldset')).toBe(body)
    expect(body.disabled).toBe(true)
    expect(list.textContent).toBe(labels)

    await act(async () => {
      fireEvent.click(byId('privacy-erase-cancel')!)
      fireEvent.click(byId('modal-close')!)
      fireEvent.click(byId('modal')!)
      fireEvent.keyDown(byId('modal')!, { key: 'Escape' })
      fireEvent.click(confirm)
      vi.advanceTimersByTime(299)
    })
    expect(byId('privacy-erase-list')).toBe(list)
    expect(spinner()).toBeNull()
    expect(confirm.classList.contains('disabled')).toBe(false)
    await act(async () => {
      vi.advanceTimersByTime(1)
    })
    expect(spinner()).not.toBeNull()
    expect(confirm.classList.contains('disabled')).toBe(true)
    expect(actions.dispatch).toHaveBeenCalledOnce()
    expect(connection.forgetProtectedModePass).toHaveBeenCalledOnce()

    await act(async () => {
      answer({})
    })
    expect(byId('privacy-erase-done')).not.toBeNull()
    expect(byId('privacy-erase-confirm')).toBeNull()
  })

  it('becomes the outcome, naming what went and that the account did not', async () => {
    render(
      <HilosPrivacyPage
        context={eraseContext(
          fakeConnection(),
          fakeActions(Promise.resolve({})),
        )}
      />,
    )
    await erase()

    const done = byId('privacy-erase-done')
    expect(done).not.toBeNull()
    expect(byId('privacy-erase')).toBeNull()
    expect(done?.textContent).toContain('a new one begun')
    expect(done?.textContent).toContain('not account deletion')
    expect(byId('privacy-erase-partial')).toBeNull()
  })

  it('tells the truth in parts when the server half refused', async () => {
    render(
      <HilosPrivacyPage
        context={eraseContext(
          fakeConnection(),
          fakeActions(
            Promise.reject(
              new ActionError('hilos_browser_erase', 'fail', 'Session is gone'),
            ),
          ),
        )}
      />,
    )
    await erase()

    expect(byId('privacy-erase-partial')?.textContent).toContain(
      'Session is gone',
    )
    // What the browser half did stands: the outcome still names it.
    expect(byId('privacy-erase-done')?.textContent).toContain('were erased')
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

async function policyUpdate(run: () => void = () => {}): Promise<void> {
  await act(async () => {
    run()
  })
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

  const view = render(
    <HilosPrivacyPage context={world.context}>
      <p data-id="policy-intro">A demonstration.</p>
    </HilosPrivacyPage>,
  )
  const dispose = () => view.unmount()
  const replace = (context: typeof world.context) => {
    view.rerender(<HilosPrivacyPage context={context} />)
  }

  await policyUpdate()
  return { world, dispatch, dispose, replace, view }
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
  expect(document.querySelectorAll('h1')).toHaveLength(1)
  expect(policyNode('hilos-page-head-title')!.textContent?.trim()).toBe(
    'Privacy',
  )
  const icon = policyNode('hilos-page-head-block')?.querySelector(
    'i.bi-shield-lock',
  )
  expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
  expect(
    policyNode('hilos-page-head-block')?.querySelector('p')?.textContent,
  ).toBe(
    'What this project keeps about you: the revision in force, every one before it, and wiping this browser.',
  )
  expect(policyNode('hilos-breadcrumb')).toBeNull()
  expect(policyNode('hilos-page-tabs')).toBeNull()
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

it('forwards optional navigation to the shared head and updates it without remounting', async () => {
  const { world, view } = await drawPolicy({})
  const heading = policyNode('hilos-page-head-title')

  view.rerender(
    <HilosPrivacyPage context={world.context} navigation={publicNavigation()}>
      <p data-id="policy-intro">A demonstration.</p>
    </HilosPrivacyPage>,
  )
  const tabs = policyNode('hilos-page-tabs')
  expect(tabs?.getAttribute('aria-label')).toBe('Public pages')
  expect(
    [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
      link.textContent,
      link.getAttribute('href'),
    ]),
  ).toEqual([
    ['Public page', '/projects/first/fixture_public_page'],
    ['Revision history', '/projects/first/fixture_history'],
  ])
  expect(
    tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
  ).toBe('hilos-page-tab-fixture_public_page')

  view.rerender(
    <HilosPrivacyPage
      context={world.context}
      navigation={publicNavigation(
        'fixture_history',
        'next',
        'More public pages',
      )}
    >
      <p data-id="policy-intro">A demonstration.</p>
    </HilosPrivacyPage>,
  )
  expect(policyNode('hilos-page-head-title')).toBe(heading)
  expect(tabs?.getAttribute('aria-label')).toBe('More public pages')
  expect(
    tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
  ).toBe('hilos-page-tab-fixture_history')
  expect(
    tabs
      ?.querySelector('[data-id="hilos-page-tab-fixture_history"]')
      ?.getAttribute('href'),
  ).toBe('/projects/next/fixture_history')
})

it('reads the public policy without acceptance or a history interface', async () => {
  await drawPolicy({ legalPrivacy: privacySection })
  expect(policyNode('hilos-page-head-title')!.textContent?.trim()).toBe(
    'Privacy',
  )
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
  const comparison = policyNode('privacy-changes-modal')!
  const body = comparison.closest('fieldset')!
  const text = comparison.textContent
  expect(body.disabled).toBe(false)
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
  expect(policyNode('privacy-changes-modal')).toBe(comparison)
  expect(comparison.closest('fieldset')).toBe(body)
  expect(body.disabled).toBe(true)
  expect(comparison.textContent).toBe(text)
  expect(policyNode('privacy-changes-accept')!.matches(':disabled')).toBe(false)
  await policyUpdate(() => {
    policyNode('privacy-changes-close')!.click()
    policyNode('modal-close')!.click()
    policyNode('modal')!.click()
    policyNode('modal')!.dispatchEvent(
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
  const body = slot.closest('fieldset')!
  expect(body.disabled).toBe(false)
  await policyUpdate(() => policyNode('privacy-changes-accept')!.click())
  expect(body.disabled).toBe(true)
  await policyUpdate(() =>
    reject(
      new ActionError('hilos_legal_accept', 'fail', 'This policy has changed.'),
    ),
  )
  expect(policyNode('privacy-changes-modal')).not.toBeNull()
  expect(slot.closest('fieldset')).toBe(body)
  expect(body.disabled).toBe(false)
  expect(slot.textContent).toContain('This policy has changed.')
  expect(
    policyNode('privacy-changes-refusal-details')!.matches(':disabled'),
  ).toBe(false)
  expect(policyNode('privacy-changes-close')!.matches(':disabled')).toBe(false)
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
