import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  ScopeManager,
  HilosPages,
  createSignal,
  pageIdentity,
  resolveHilosPath,
  bindAdminAccess,
  hilosToasts,
  type HilosRouter,
  type HilosPageKey,
  type HilosConnection,
  type PageRouteMatch,
} from '@hilos/core/base'
import type { HilosCommunicationsContext } from '@hilos/core/admin/communications'
import { act, fireEvent, render } from '@testing-library/react'
import { HilosCommunicationsChannelFramePage } from '../src/admin/communications/HilosCommunicationsChannelFramePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
function mountFrame(context: HilosCommunicationsContext, router: HilosRouter) {
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosCommunicationsChannelFramePage context={context} />
    </HilosRouterContext.Provider>,
  )
  return {
    destroy: () => view.unmount(),
    step: async (run: () => void) => {
      await act(async () => {
        run()
        await Promise.resolve()
      })
    },
  }
}

const initialFrame = {
  channel: 'email',
  html: '<main>[[:content]]</main>',
  source: 'settings',
  previewHtml: '<main>482913</main>',
}
const disposers: (() => void)[] = []
afterEach(() => {
  for (const off of disposers.splice(0).reverse()) off()
  hilosToasts.clear()
})
function element<T extends HTMLElement = HTMLElement>(id: string): T | null {
  return document.querySelector<T>(`[data-id="${id}"]`)
}
function harness(viewer = false, hidden = false) {
  const scopes = new ScopeManager()
  scopes.session.data.set('adminViewMode', viewer)
  disposers.push(bindAdminAccess(scopes))
  const page = HilosPages.COMMUNICATIONS_CHANNEL_FRAME
  const scope = scopes.openPage(page)
  const summary = {
    channel: 'email',
    label: 'Email',
    enabled: true,
    configured: true,
    driver: 'smtp',
    missingFields: 0,
    fieldCount: 6,
    secretFieldCount: 1,
    overriddenFieldCount: 0,
  }
  scope.data.set('channelSummary', summary)
  scope.data.set('pageLabel', 'Letter frame')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.COMMUNICATIONS_CHANNEL,
    label: 'Channel',
    lead: 'Delivery channels.',
    icon: 'bi-envelope-paper',
  })
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
    { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Channel' },
  ])
  scope.data.set('pageTabs', [
    { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Overview' },
    {
      page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
      label: 'Configuration',
    },
    { page: HilosPages.COMMUNICATIONS_DELIVERIES, label: 'Deliveries' },
    { page, label: 'Letter frame' },
  ])
  scope.data.set('pageChildren', [])
  function put(frame: unknown) {
    scope.data.set('letterFrame', frame)
  }
  put(
    hidden
      ? {
          ...initialFrame,
          html: { _hidden: true },
          previewHtml: { _hidden: true },
        }
      : initialFrame,
  )
  const route = createSignal<PageRouteMatch>({
    page,
    params: { channelId: 'email' },
    admin: true,
  })
  const router = {
    currentRoute: route,
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (key: string, params?: Record<string, string>) =>
      resolveHilosPath(key as HilosPageKey, params),
    navigate: () => {},
  } as unknown as HilosRouter
  const listeners = new Map<string, Set<(event: never) => void>>()
  const sent: { action: string; payload: unknown; requestId?: string }[] = []
  const connection = {
    on(name: string, listener: (event: never) => void) {
      const set = listeners.get(name) ?? new Set()
      listeners.set(name, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
    sendAction(action: string, payload: unknown, requestId?: string) {
      sent.push({ action, payload, requestId })
      return true
    },
  } as unknown as HilosConnection
  const context: HilosCommunicationsContext = {
    connection,
    scopes,
    actions: new ActionLifecycle(connection),
  }
  const mounted = mountFrame(context, router)
  disposers.push(mounted.destroy)
  async function step(run: () => void = () => {}) {
    await mounted.step(run)
  }
  async function click(id: string) {
    await step(() => {
      const target = element<HTMLButtonElement>(id)
      expect(target).not.toBeNull()
      target!.click()
    })
  }
  async function type(html: string) {
    await step(() => {
      const input = element<HTMLTextAreaElement>('hilos-channel-frame-html')!
      fireEvent.change(input, { target: { value: html } })
    })
  }
  async function reply(success = true) {
    await step(() => {
      for (const listener of listeners.get(
        success ? 'actionSuccess' : 'actionError',
      ) ?? [])
        listener({
          ...sent.at(-1),
          kind: success ? 'actionSuccess' : 'actionError',
          reason: 'Frame refused.',
        } as never)
    })
  }
  return { scopes, scope, summary, sent, put, step, click, type, reply }
}

describe('HilosCommunicationsChannelFramePage', () => {
  it('renders one live owner head, four tabs and only the server preview in an empty sandbox', async () => {
    const h = harness()
    await h.step()
    const preview = element<HTMLIFrameElement>('hilos-channel-frame-preview')!
    expect(preview.getAttribute('sandbox')).toBe('')
    expect(preview.getAttribute('title')).toBe('Letter frame preview')
    expect(preview.getAttribute('srcdoc')).toBe('<main>482913</main>')
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(document.querySelector('h1')!.textContent).toBe('Email')
    expect(
      document.querySelectorAll('[data-id^="hilos-page-tab-"]'),
    ).toHaveLength(4)
    expect(
      element(
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_CHANNEL_FRAME}`,
      )!.getAttribute('aria-current'),
    ).toBe('page')
    await h.step(() =>
      h.scope.data.set('channelSummary', { ...h.summary, label: 'Live mail' }),
    )
    expect(document.querySelector('h1')!.textContent).toBe('Live mail')
    await h.click('hilos-channel-frame-edit')
    const dialog = element('modal')!
    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-label')).toBe('Edit · Letter frame')
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(true)
    await h.type('<draft>[[:content]]</draft>')
    expect(preview.getAttribute('srcdoc')).toBe('<main>482913</main>')
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(false)
    expect(h.sent).toEqual([])
  })
  it('keeps a dirty draft behind Discard changes and Keep editing', async () => {
    const h = harness()
    await h.step()
    await h.click('hilos-channel-frame-edit')
    await h.type('<draft>[[:content]]</draft>')
    await h.click('hilos-channel-frame-edit-cancel')
    expect(
      element('modal-confirm-title')?.textContent ?? document.body.textContent,
    ).toContain('Discard changes?')
    await h.click('modal-confirm-cancel')
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
    ).toBe('<draft>[[:content]]</draft>')
    await h.click('hilos-channel-frame-edit-cancel')
    await h.click('modal-confirm-discard')
    expect(element('hilos-channel-frame-html')).toBeNull()
    expect(h.sent).toEqual([])
  })
  it('waits for a tracked failure, retains the draft and closes only on success', async () => {
    const h = harness()
    await h.step()
    await h.click('hilos-channel-frame-edit')
    await h.type('<saved>[[:content]]</saved>')
    await h.click('hilos-channel-frame-save')
    expect(h.sent).toHaveLength(1)
    expect(h.sent[0]).toMatchObject({
      action: 'communications_channel_set',
      payload: {
        channel: 'email',
        field: 'frame',
        value: '<saved>[[:content]]</saved>',
      },
    })
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-edit-cancel')!.disabled,
    ).toBe(true)
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.disabled,
    ).toBe(true)
    await h.reply(false)
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
    ).toBe('<saved>[[:content]]</saved>')
    expect(document.body.textContent).toContain('Frame refused.')
    await h.click('hilos-channel-frame-save')
    await h.reply()
    expect(element('hilos-channel-frame-html')).toBeNull()
  })
  it('merges remote frame and reset changes while retaining a draft and shows both conflict values', async () => {
    const h = harness()
    await h.step()
    await h.click('hilos-channel-frame-edit')
    await h.type('<mine>[[:content]]</mine>')
    await h.step(() =>
      h.put({
        ...initialFrame,
        html: '<remote>[[:content]]</remote>',
        previewHtml: '<remote>482913</remote>',
      }),
    )
    expect(element('hilos-channel-frame-incoming')!.textContent).toContain(
      '<remote>[[:content]]</remote>',
    )
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(true)
    await h.click('conflict-accept-mine')
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
    ).toBe('<mine>[[:content]]</mine>')
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(false)
    await h.step(() =>
      h.put({
        ...initialFrame,
        source: 'project',
        html: '<project>[[:content]]</project>',
      }),
    )
    await h.click('conflict-accept-theirs')
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
    ).toBe('<project>[[:content]]</project>')
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(true)
    expect(h.sent).toEqual([])
  })
  it.each([
    ['project', 'Project'],
    ['default', 'Default'],
  ])(
    'resets through confirmation and follows a reset made elsewhere to %s',
    async (source, label) => {
      const h = harness()
      await h.step()
      await h.click('hilos-channel-frame-reset')
      const dialog = element('modal')!
      expect(dialog.getAttribute('role')).toBe('dialog')
      expect(
        Array.from(dialog.querySelectorAll('dl > dt'), (dt) =>
          dt.textContent?.trim(),
        ),
      ).toEqual(['Now', 'Back to'])
      const now = element('hilos-channel-frame-reset-now')!
      const backTo = element('hilos-channel-frame-reset-back-to')!
      expect(now.tagName).toBe('DD')
      expect(now.querySelector('.badge')!.textContent?.trim()).toBe('Override')
      expect(backTo.tagName).toBe('DD')
      expect(backTo.textContent?.replace(/\s+/g, ' ').trim()).toBe(
        'the project frame, or the framework frame when the project has none',
      )
      expect(h.sent).toEqual([])
      await h.step(() => h.put({ ...initialFrame, source }))
      expect(element('modal')).toBe(dialog)
      expect(now.querySelector('.badge')!.textContent?.trim()).toBe(label)
      expect(
        element<HTMLButtonElement>('hilos-channel-frame-reset-confirm')!
          .disabled,
      ).toBe(true)
      expect(element('hilos-channel-frame-reset-state')!.textContent).toContain(
        'Already reset elsewhere.',
      )
      expect(backTo.textContent?.replace(/\s+/g, ' ').trim()).toBe(
        'the project frame, or the framework frame when the project has none',
      )
      expect(h.sent).toEqual([])
      await h.step(() => h.put(initialFrame))
      expect(now.querySelector('.badge')!.textContent?.trim()).toBe('Override')
      await h.click('hilos-channel-frame-reset-confirm')
      expect(h.sent).toHaveLength(1)
      expect(h.sent[0]).toMatchObject({
        action: 'communications_channel_reset',
        payload: { channel: 'email', field: 'frame' },
      })
      await h.reply()
      expect(element('hilos-channel-frame-reset-confirm')).toBeNull()
    },
  )
  it('shows Hidden marks for a viewer without creating an iframe or textarea and keeps Cancel', async () => {
    const h = harness(true, true)
    await h.step()
    expect(element('hilos-channel-frame-preview')).toBeNull()
    expect(document.querySelectorAll('iframe')).toHaveLength(0)
    expect(document.body.textContent).toContain('Hidden')
    await h.click('hilos-channel-frame-edit')
    expect(element('hilos-channel-frame-html')).toBeNull()
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(true)
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-edit-cancel')!.disabled,
    ).toBe(false)
    expect(h.sent).toEqual([])
  })
  it('retains an extractable draft and blocks submit when the frame scope clears', async () => {
    const h = harness()
    await h.step()
    await h.click('hilos-channel-frame-edit')
    await h.type('<draft>[[:content]]</draft>')
    await h.step(() => h.put(undefined))
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
    ).toBe('<draft>[[:content]]</draft>')
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(true)
    await h.step(() =>
      element('hilos-channel-frame-html')!
        .closest('form')!
        .dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }),
        ),
    )
    expect(h.sent).toEqual([])
  })
  it.each(['project', 'default'])(
    'edits the effective %s frame and sends a native submit through the tracked save',
    async (source) => {
      const h = harness()
      await h.step(() => h.put({ ...initialFrame, source }))
      expect(
        element<HTMLButtonElement>('hilos-channel-frame-reset')!.disabled,
      ).toBe(true)
      await h.click('hilos-channel-frame-edit')
      expect(
        element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
      ).toBe(initialFrame.html)
      expect(
        element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
      ).toBe(true)
      await h.type('<native>[[:content]]</native>')
      await h.step(() =>
        element('hilos-channel-frame-html')!
          .closest('form')!
          .dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
          ),
      )
      expect(h.sent).toHaveLength(1)
      expect(h.sent[0]).toMatchObject({
        action: 'communications_channel_set',
        payload: {
          channel: 'email',
          field: 'frame',
          value: '<native>[[:content]]</native>',
        },
      })
      await h.reply()
      expect(element('hilos-channel-frame-html')).toBeNull()
    },
  )
  it('allows a viewer to read a visible frame and draft while the standard Save and Reset controls stay locked', async () => {
    const h = harness(true)
    await h.step()
    await h.click('hilos-channel-frame-edit')
    expect(
      element<HTMLTextAreaElement>('hilos-channel-frame-html')!.value,
    ).toBe(initialFrame.html)
    await h.type('<viewer>[[:content]]</viewer>')
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-save')!.disabled,
    ).toBe(true)
    expect(
      element<HTMLButtonElement>('hilos-channel-frame-edit-cancel')!.disabled,
    ).toBe(false)
    await h.click('hilos-channel-frame-save')
    expect(h.sent).toEqual([])
  })
})
