import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import HilosLegalSettingsPage from './HilosLegalSettingsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import {
  ActionError,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  ScopeManager,
  type HilosConnection,
  type HilosLegalContext,
  type HilosPageIdentity,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core/base'

/**
 * The live window is real; only its transport and the rejected action are replaced.
 *
 * @param hold Keep each reply in flight until the test settles or refuses it.
 * @param initialRefusal Refuse the carrier in the first page response.
 */
function harness(hold = false, initialRefusal = false) {
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const calls: Array<{ action: string; payload: unknown }> = []
  const focus: string[] = []
  const held: Array<{ settle(): void; refuse(): void }> = []
  const refusal = () =>
    new ActionError('legal_setting_set', 'fail', 'Server refused this choice')
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LEGAL_SETTINGS)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal' && initialRefusal)
          listener({
            type: 'page_response',
            data: {
              page: HilosPages.LEGAL_SETTINGS,
              payload: {
                data: {},
                refusedWindows: {
                  hilosLegalSettings: { errorCode: 'internal_error' },
                },
              },
            },
          } as never)
        return () => group.delete(listener)
      },
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
      sendTableRendered() {},
      sendTableRowFocus(_page: string, _tableKey: string, rowKey: string) {
        focus.push(rowKey)
      },
    },
    actions: {
      dispatch(action: string, payload: unknown) {
        calls.push({ action, payload })
        return {
          requestId: '1',
          loading: createSignal(false),
          done: hold
            ? new Promise((resolve, reject) =>
                held.push({
                  settle: () => resolve({}),
                  refuse: () => reject(refusal()),
                }),
              )
            : Promise.reject(refusal()),
        }
      },
    },
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_SETTINGS,
      params: {},
      admin: true,
    }),
    pageIdentity: createSignal<HilosPageIdentity>({
      label: 'Settings',
      lead: '',
      breadcrumb: [],
      children: [],
    }),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return {
    context,
    router,
    calls,
    focus,
    held,
    refuse() {
      for (const listener of listeners.get('tableWindowRefused') ?? [])
        listener({
          type: 'table_window_refused',
          data: {
            page: HilosPages.LEGAL_SETTINGS,
            tableKey: 'hilosLegalSettings',
            errorCode: 'internal_error',
          },
        } as never)
    },
    push(
      value: unknown,
      {
        refusal = 'freeze',
        consentDefault = 'checkbox',
        refusalDefault = 'freeze',
      }: {
        refusal?: unknown
        consentDefault?: unknown
        refusalDefault?: unknown
      } = {},
    ) {
      for (const listener of listeners.get('tableWindow') ?? [])
        listener({
          data: {
            page: HilosPages.LEGAL_SETTINGS,
            tableKey: 'hilosLegalSettings',
            rows: [
              {
                rowKey: 'legal.consent_form',
                slots: { setting: { value, defaultValue: consentDefault } },
              },
              {
                rowKey: 'legal.refusal_after_deadline',
                slots: {
                  setting: { value: refusal, defaultValue: refusalDefault },
                },
              },
            ],
            totalCount: 2,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
          },
        } as never)
    },
  }
}

/** A modal is teleported outside the mounted table. */
function input(): HTMLSelectElement {
  return document.querySelector(
    '[data-id="legal-setting-input"]',
  ) as HTMLSelectElement
}
function save(): HTMLButtonElement {
  return document.querySelector(
    '[data-id="legal-setting-save"]',
  ) as HTMLButtonElement
}

describe('legal setting Vue sections', () => {
  it('names both settings sections and draws their live values, defaults and edit controls', async () => {
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('line', { refusal: 'remind' })
      await nextTick()

      expect(view.findAll('h1')).toHaveLength(1)
      expect(view.text()).toContain(
        'Two settings control how consent is given and what happens after a deadline. Document texts and deadlines stay in code.',
      )
      const sections = new Map(
        view
          .findAll('section[aria-labelledby]')
          .map((section) => [section.get('h2').text(), section]),
      )
      expect(sections.size).toBe(2)
      for (const [key, title, hint, value, defaultValue] of [
        [
          'legal.consent_form',
          'Consent at registration',
          'How a person agrees when creating an account.',
          'Line below the button',
          'Checkbox',
        ],
        [
          'legal.refusal_after_deadline',
          'Refusal after the deadline',
          'What happens when the acceptance window ends.',
          'Keep reminding',
          'Freeze access',
        ],
      ] as const) {
        const section = sections.get(title)!
        expect(section.attributes('aria-labelledby')).toBe(
          section.get('h2').attributes('id'),
        )
        expect(section.text()).toContain(hint)
        expect(
          section.get(`[data-id="legal-setting-value-${key}"]`).text(),
        ).toBe(value)
        expect(section.get('dt').text()).toBe('Default:')
        expect(section.get('dd').text()).toBe(defaultValue)
        const edit = section.get<HTMLButtonElement>(
          `[data-id="legal-setting-edit-${key}"]`,
        )
        expect(edit.element.tagName).toBe('BUTTON')
        expect(edit.attributes('aria-label')).toBe(`Edit ${title}`)
        expect(edit.element.disabled).toBe(false)
      }
      expect(view.find('[role="table"], table').exists()).toBe(false)
      expect(view.find('[data-id^="legal-setting-preview-"]').exists()).toBe(
        false,
      )
      expect(view.text()).not.toContain('Previews')
      expect(view.text()).not.toContain('What is not a setting')
      expect(view.find('input, select, textarea').exists()).toBe(false)

      h.push('checkbox', {
        refusal: 'freeze',
        consentDefault: 'line',
        refusalDefault: 'remind',
      })
      await nextTick()
      expect(
        view.get('[data-id="legal-setting-value-legal.consent_form"]').text(),
      ).toBe('Checkbox')
      expect(sections.get('Consent at registration')!.get('dd').text()).toBe(
        'Line below the button',
      )
      expect(
        view
          .get('[data-id="legal-setting-value-legal.refusal_after_deadline"]')
          .text(),
      ).toBe('Freeze access')
      expect(sections.get('Refusal after the deadline')!.get('dd').text()).toBe(
        'Keep reminding',
      )

      const edit = view.get<HTMLButtonElement>(
        '[data-id="legal-setting-edit-legal.refusal_after_deadline"]',
      )
      edit.element.focus()
      await edit.trigger('click')
      await flushPromises()
      const modal = document.querySelector('[role="dialog"]')!
      expect(modal.getAttribute('aria-modal')).toBe('true')
      expect(modal.getAttribute('aria-label')).toBe(
        'Refusal after the deadline',
      )
      expect(h.focus).toEqual(['legal.refusal_after_deadline'])
      expect(input().value).toBe('freeze')
      expect(
        Array.from(input().options, (option) => option.textContent?.trim()),
      ).toEqual(['Freeze access', 'Keep reminding'])
      expect(document.activeElement).toBe(input())
      document
        .querySelector<HTMLButtonElement>('[data-id="legal-setting-cancel"]')!
        .click()
      await flushPromises()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(h.focus).toEqual(['legal.refusal_after_deadline', ''])
      expect(document.activeElement).toBe(edit.element)
    } finally {
      view.unmount()
    }
  })

  it.each([true, false])(
    'shows a carrier refusal separately from mutation errors and restores the sections and editor (first response: %s)',
    async (firstResponse) => {
      const h = harness(false, firstResponse)
      const view = mount(HilosLegalSettingsPage, {
        attachTo: document.body,
        props: { context: h.context },
        global: { provide: { [hilosRouterKey as symbol]: h.router } },
      })
      try {
        if (!firstResponse) {
          h.push('checkbox')
          await nextTick()
          expect(view.findAll('section[aria-labelledby]')).toHaveLength(2)
          h.refuse()
        }
        await nextTick()
        const unavailable = view.get('[data-id="hilos-table-unavailable"]')
        expect(unavailable.attributes('role')).toBe('status')
        expect(
          unavailable.get('[data-id="hilos-table-unavailable-title"]').text(),
        ).toBe('List unavailable')
        expect(view.findAll('section[aria-labelledby]')).toHaveLength(0)
        expect(view.find('[role="table"], table').exists()).toBe(false)
        expect(view.find('[data-id="hilos-skeleton"]').exists()).toBe(false)
        expect(view.find('[data-id="hilos-hidden"]').exists()).toBe(false)
        expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
          'Settings',
        ])
        expect(view.text()).toContain(
          'Two settings control how consent is given',
        )
        expect(view.text()).not.toContain('Server refused this choice')
        expect(document.querySelector('[role="dialog"]')).toBeNull()
        expect(h.calls).toHaveLength(0)

        h.push('line', { refusal: 'remind' })
        await nextTick()
        expect(view.find('[data-id="hilos-table-unavailable"]').exists()).toBe(
          false,
        )
        expect(view.findAll('section[aria-labelledby]')).toHaveLength(2)
        expect(
          view.get('[data-id="legal-setting-value-legal.consent_form"]').text(),
        ).toBe('Line below the button')
        expect(
          view
            .get('[data-id="legal-setting-value-legal.refusal_after_deadline"]')
            .text(),
        ).toBe('Keep reminding')
        expect(view.findAll('dd').map((value) => value.text())).toEqual([
          'Checkbox',
          'Freeze access',
        ])
        const edit = view.get<HTMLButtonElement>(
          '[data-id="legal-setting-edit-legal.consent_form"]',
        )
        expect(edit.attributes('aria-label')).toBe(
          'Edit Consent at registration',
        )
        edit.element.focus()
        await edit.trigger('click')
        await flushPromises()
        expect(input().value).toBe('line')
        expect(document.activeElement).toBe(input())
        expect(save().disabled).toBe(true)
        input().value = 'checkbox'
        input().dispatchEvent(new Event('change', { bubbles: true }))
        await nextTick()
        expect(save().disabled).toBe(false)
        save().click()
        await flushPromises()
        expect(h.calls).toEqual([
          {
            action: 'legal_setting_set',
            payload: { key: 'legal.consent_form', value: 'checkbox' },
          },
        ])
        expect(input().value).toBe('checkbox')
        expect(
          document.querySelector('[role="dialog"]')!.textContent,
        ).toContain('Server refused this choice')
      } finally {
        view.unmount()
      }
    },
  )
})

describe('legal setting Vue modal', () => {
  it('merges a pristine incoming value, locks an unchanged save and keeps a refused draft', async () => {
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      await view
        .find('[data-id="legal-setting-edit-legal.consent_form"]')
        .trigger('click')
      expect(input().value).toBe('checkbox')
      expect(save().disabled).toBe(true)
      h.push('line')
      await nextTick()
      expect(input().value).toBe('line')
      expect(save().disabled).toBe(true)
      expect(
        document.querySelector('[data-id="legal-setting-notice"]')?.textContent,
      ).toContain('Updated just now')
      input().value = 'checkbox'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()
      expect(save().disabled).toBe(false)
      save().click()
      await flushPromises()
      await nextTick()
      expect(
        view.get('[data-id="legal-setting-value-legal.consent_form"]').text(),
      ).toBe('Line below the button')
      expect(h.calls).toEqual([
        {
          action: 'legal_setting_set',
          payload: { key: 'legal.consent_form', value: 'checkbox' },
        },
      ])
      expect(input().value).toBe('checkbox')
      expect(
        document.querySelector('[data-id="hilos-action-error"]')?.textContent,
      ).toContain('Server refused this choice')
    } finally {
      view.unmount()
    }
  })

  it.each(['conflict-accept-mine', 'conflict-accept-theirs'])(
    'keeps the draft and blocks submission until %s resolves the conflict',
    async (choice) => {
      const h = harness()
      const view = mount(HilosLegalSettingsPage, {
        attachTo: document.body,
        props: { context: h.context },
        global: { provide: { [hilosRouterKey as symbol]: h.router } },
      })
      try {
        // A previously saved value outside the current choices still supplies
        // the baseline; the draft and incoming choice then differ from it.
        h.push('legacy-consent')
        await nextTick()
        await view
          .get('[data-id="legal-setting-edit-legal.consent_form"]')
          .trigger('click')
        input().value = 'checkbox'
        input().dispatchEvent(new Event('change', { bubbles: true }))
        await nextTick()
        h.push('line')
        await nextTick()
        expect(input().value).toBe('checkbox')
        expect(save().disabled).toBe(true)
        expect(
          document.querySelector('[data-id="legal-setting-notice"]')
            ?.textContent,
        ).toContain('Changed elsewhere to "Line below the button".')
        input().form!.dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }),
        )
        await flushPromises()
        expect(h.calls).toEqual([])
        expect(input().value).toBe('checkbox')

        document
          .querySelector<HTMLButtonElement>(`[data-id="${choice}"]`)!
          .click()
        await nextTick()
        expect(
          document.querySelector('[data-id="conflict-choices"]'),
        ).toBeNull()
        if (choice === 'conflict-accept-mine') {
          expect(input().value).toBe('checkbox')
          expect(save().disabled).toBe(false)
          save().click()
          await flushPromises()
          expect(h.calls).toEqual([
            {
              action: 'legal_setting_set',
              payload: { key: 'legal.consent_form', value: 'checkbox' },
            },
          ])
          expect(input().value).toBe('checkbox')
        } else {
          expect(input().value).toBe('line')
          expect(save().disabled).toBe(true)
          input().form!.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
          )
          await flushPromises()
          expect(h.calls).toEqual([])
          expect(document.querySelector('[data-id="modal"]')).toBeNull()
        }
      } finally {
        view.unmount()
      }
    },
  )

  it('uses the same save gate for form submission and waits for the owner reply', async () => {
    const h = harness(true)
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      const edit = view.get('[data-id="legal-setting-edit-legal.consent_form"]')
      await edit.trigger('click')
      input().form!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
      await flushPromises()
      expect(h.calls).toEqual([])
      expect(document.querySelector('[data-id="modal"]')).toBeNull()

      await edit.trigger('click')
      input().value = 'line'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()
      const form = input().form!
      form.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
      await nextTick()
      form.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
      await nextTick()
      expect(h.calls).toEqual([
        {
          action: 'legal_setting_set',
          payload: { key: 'legal.consent_form', value: 'line' },
        },
      ])
      expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
      expect(
        view.get('[data-id="legal-setting-value-legal.consent_form"]').text(),
      ).toBe('Checkbox')

      h.held[0]!.settle()
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      h.push('line')
      await nextTick()
      expect(
        view.get('[data-id="legal-setting-value-legal.consent_form"]').text(),
      ).toBe('Line below the button')
    } finally {
      view.unmount()
    }
  })

  it('holds the modal and locks its body while the save is in flight, and frees the same list after a refusal', async () => {
    const h = harness(true)
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      await view
        .find('[data-id="legal-setting-edit-legal.consent_form"]')
        .trigger('click')
      input().value = 'line'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()
      const field = input()
      // The fieldset the modal stands its body in; the save in flight disables it.
      const body = document.querySelector<HTMLFieldSetElement>(
        '[data-id="modal"] .modal-body > fieldset',
      )
      const cancel = document.querySelector<HTMLButtonElement>(
        '[data-id="legal-setting-cancel"]',
      )!
      const close = document.querySelector<HTMLButtonElement>(
        '[data-id="modal-close"]',
      )!
      const modal = document.querySelector<HTMLElement>('[data-id="modal"]')!
      expect(body?.contains(field)).toBe(true)
      expect(body?.disabled).toBe(false)

      save().click()
      await nextTick()
      expect(h.calls).toHaveLength(1)
      expect(body?.disabled).toBe(true)
      expect(cancel.disabled).toBe(true)
      expect(close.disabled).toBe(true)
      close.click()
      cancel.click()
      modal.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      modal.click()
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBe(modal)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

      h.held[0]!.refuse()
      await flushPromises()
      expect(input()).toBe(field)
      expect(field.value).toBe('line')
      expect(body?.disabled).toBe(false)
      expect(field.disabled).toBe(false)
      expect(cancel.disabled).toBe(false)
      expect(close.disabled).toBe(false)
      expect(
        document.querySelector('[data-id="hilos-action-error"]')?.textContent,
      ).toContain('Server refused this choice')

      save().click()
      await nextTick()
      expect(h.calls).toHaveLength(2)
      h.held[1]!.settle()
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    } finally {
      view.unmount()
    }
  })
})

describe('legal setting Vue modal with the value hidden (HIL-1260)', () => {
  it('draws the mark in the cell and in the modal, with no list and nothing to discard', async () => {
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
      attachTo: document.body,
    })
    h.push(
      { _hidden: true },
      {
        refusal: { _hidden: true },
        consentDefault: { _hidden: true },
        refusalDefault: { _hidden: true },
      },
    )
    await flushPromises()

    const cell = view.get('[data-id="legal-setting-value-legal.consent_form"]')
    expect(cell.find('[data-id="hilos-hidden"]').text()).toBe('Hidden')
    for (const key of ['legal.consent_form', 'legal.refusal_after_deadline']) {
      expect(view.get(`[data-id="legal-setting-value-${key}"]`).text()).toBe(
        'Hidden',
      )
    }
    for (const section of view.findAll('section[aria-labelledby]')) {
      expect(section.get('dt').text()).toBe('Default:')
      expect(section.get('dd').text()).toBe('Hidden')
    }
    expect(view.findAll('[data-id="hilos-hidden"]')).toHaveLength(4)

    await view
      .get('[data-id="legal-setting-edit-legal.consent_form"]')
      .trigger('click')
    await flushPromises()
    expect(input()).toBeNull()
    const modal = document.querySelector('[data-id="modal"]')
    expect(
      modal?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(save().disabled).toBe(true)
    ;(
      document.querySelector('[data-id="legal-setting-cancel"]') as HTMLElement
    ).click()
    await flushPromises()
    expect(
      document.querySelector('[data-id="modal-confirm-discard"]'),
    ).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    view.unmount()
  })
})

describe('legal setting Vue modal in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('a viewer opens a setting, may pick a value and has nothing to save it with', async () => {
    const session = bindSession()
    session.handshake(null, true)
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      const editButton = view.find<HTMLButtonElement>(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      )
      expect(editButton.element.disabled).toBe(false)
      await editButton.trigger('click')

      expect(input().disabled).toBe(false)
      input().value = 'line'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()

      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      save().click()
      await flushPromises()
      await nextTick()
      expect(h.calls).toEqual([])

      const cancelButton = document.querySelector<HTMLButtonElement>(
        '[data-id="legal-setting-cancel"]',
      )!
      expect(cancelButton.disabled).toBe(false)
      cancelButton.click()
      await nextTick()
      expect(input()).toBeNull()
    } finally {
      view.unmount()
    }
  })

  it('an admin on a node in the mode edits as today', async () => {
    const session = bindSession()
    session.handshake({ id: 1, admin: true }, true)
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      const editButton = view.find<HTMLButtonElement>(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      )
      expect(editButton.element.disabled).toBe(false)
      await editButton.trigger('click')

      expect(input().disabled).toBe(false)
      input().value = 'line'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()

      expect(save().disabled).toBe(false)
      expect(save().getAttribute('aria-describedby')).toBeNull()
    } finally {
      view.unmount()
    }
  })
})
