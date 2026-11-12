// The semantic union of ThemeMenu's existing three SDK suites.
import {
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosThemePick,
  hilosToasts,
  setHilosThemePick,
  type ThemePick,
} from '@hilos/core/base'
import { vi } from 'vitest'

import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../core/src/theme/themeBrowser.js'
import {
  byId,
  key,
  shown,
  type ThemeMenuRunner,
  type ThemeMenuServer,
  type ThemeMenuView,
  type WithThemeMenu,
} from './themeMenuFixtures.js'

const enabled = { switchingEnabled: true, defaultTheme: 'light' } as const
const off = { switchingEnabled: false, defaultTheme: 'light' } as const

function toggle(view: ThemeMenuView): HTMLButtonElement {
  return byId(view.root, 'nav-theme')
}

function menu(view: ThemeMenuView): HTMLElement | null {
  return view.root.querySelector('[data-id="nav-theme-menu"]')
}

function row(view: ThemeMenuView, pick: ThemePick): HTMLButtonElement {
  return byId(
    view.root,
    pick === null ? 'nav-theme-default' : `nav-theme-option-${pick}`,
  )
}

async function click(view: ThemeMenuView, target: HTMLElement): Promise<void> {
  await view.update(() => target.click())
}

async function press(
  view: ThemeMenuView,
  target: Element,
  name: string,
): Promise<void> {
  await view.update(() => key(target, name))
}

/** A deliberate focus move makes the later completion's focus return observable. */
function focusElsewhere(view: ThemeMenuView): HTMLButtonElement {
  const outside = view.root.appendChild(document.createElement('button'))
  outside.textContent = 'Elsewhere'
  outside.focus()
  return outside
}

function changeSession(
  server: ThemeMenuServer,
  change: 'off' | 'signout' | 'impersonation',
  pick: ThemePick,
): void {
  if (change === 'off') server.settings(off)
  else if (change === 'signout') server.handshake(pick, null)
  else server.impersonate()
}

/** One text of steps and explicit results, with only lifecycle supplied locally. */
export function registerThemeMenuCases(
  sdk: string,
  runner: ThemeMenuRunner,
  withThemeMenu: WithThemeMenu,
): void {
  const { describe, it, expect } = runner
  const closed = (view: ThemeMenuView): void => {
    expect(toggle(view).getAttribute('aria-expanded')).toBe('false')
    expect(shown(menu(view))).toBe(false)
  }
  const confirmed = (pick: ThemePick): void => {
    expect(hilosThemePick.get()).toBe(pick)
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
  }
  const checked = (
    view: ThemeMenuView,
    pick: Exclude<ThemePick, null>,
  ): void => {
    for (const position of ['light', 'dark', 'system'] as const) {
      const option = row(view, position)
      expect(option.getAttribute('aria-checked')).toBe(
        String(position === pick),
      )
      // The extra decorative mark conveys the same selection as aria-checked.
      expect(option.querySelectorAll('i[aria-hidden="true"]')).toHaveLength(
        position === pick ? 2 : 1,
      )
    }
  }

  describe(`ThemeMenu on ${sdk}`, () => {
    it('C01 locks the same open account menu across unavailable states and accepts only a new reconnect choice', async () => {
      await withThemeMenu(
        { account: true, shell: true, allowDisconnectedInteraction: true },
        async (view) => {
          const { server } = view
          await click(view, toggle(view))
          const opened = menu(view)
          const light = row(view, 'light')
          for (const state of [
            'connecting',
            'reconnecting',
            'disconnected',
          ] as const) {
            await view.update(() => {
              server.state(state)
              if (state === 'connecting') {
                // No framework flush between this raw frame and the stale clicks.
                row(view, null).click()
                light.click()
                expect(server.sendAction).not.toHaveBeenCalled()
                confirmed('dark')
              }
            })
            expect(server.sendAction).not.toHaveBeenCalled()
            confirmed('dark')
            expect(menu(view)).toBe(opened)
            expect(toggle(view).getAttribute('aria-expanded')).toBe('true')
            expect(toggle(view).disabled).toBe(true)
            expect(toggle(view).getAttribute('aria-describedby')).toBe(
              HILOS_CONNECTION_STATUS_TEXT_ID,
            )
            expect(
              view.root.querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
            ).not.toBeNull()
            for (const pick of ['light', 'dark', 'system', null] as const) {
              expect(row(view, pick).disabled).toBe(true)
              expect(row(view, pick).getAttribute('aria-describedby')).toBe(
                HILOS_CONNECTION_STATUS_TEXT_ID,
              )
            }
            await press(view, opened!, 'Home')
            expect(
              [...opened!.querySelectorAll<HTMLButtonElement>('button')].some(
                (button) =>
                  button.disabled && button === document.activeElement,
              ),
            ).toBe(false)
            expect(
              view.root.querySelector('[data-id="nav-theme-loading"]'),
            ).toBeNull()
          }
          await view.update(() => server.state('connected'))
          expect(menu(view)).toBe(opened)
          expect(toggle(view).disabled).toBe(false)
          expect(row(view, 'light')).toBe(light)
          await click(view, light)
          expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
            'hilos_theme_pick',
            { themePick: 'light' },
            '1',
          )
          confirmed('dark')
          expect(
            view.root.querySelector('[data-id="nav-theme-loading"]'),
          ).toBeNull()
          await view.update(() => server.reply('success'))
          await view.settle()
          confirmed('dark')
        },
      )
    })

    it('C02 keeps every guest choice and repeated reset local through disconnect states', async () => {
      await withThemeMenu(
        {
          account: true,
          shell: true,
          beforeMount: (server) => server.handshake('dark', null),
        },
        async (view) => {
          for (const [state, pick] of [
            ['connecting', 'light'],
            ['reconnecting', 'dark'],
            ['disconnected', 'system'],
          ] as const) {
            await view.update(() => view.server.state(state))
            expect(toggle(view).disabled).toBe(false)
            expect(toggle(view).getAttribute('aria-describedby')).toBeNull()
            await click(view, toggle(view))
            await click(view, row(view, pick))
            confirmed(pick)
            closed(view)
          }
          for (let attempt = 0; attempt < 2; attempt++) {
            await click(view, toggle(view))
            await click(view, row(view, null))
            confirmed(null)
            closed(view)
          }
          expect(view.server.sendAction).not.toHaveBeenCalled()
        },
      )
    })

    it('C03 reads sign-in and availability before the stale guest row is redrawn', async () => {
      await withThemeMenu(
        {
          account: true,
          shell: true,
          allowDisconnectedInteraction: true,
          beforeMount: (server) => server.handshake('dark', null),
        },
        async (view) => {
          await click(view, toggle(view))
          const light = row(view, 'light')
          await view.update(() => {
            view.server.state('disconnected')
            view.server.handshake('dark', 1)
            light.click()
            expect(view.server.sendAction).not.toHaveBeenCalled()
            confirmed('dark')
          })
          expect(view.server.sendAction).not.toHaveBeenCalled()
          confirmed('dark')
          expect(toggle(view).disabled).toBe(true)
          expect(toggle(view).getAttribute('aria-describedby')).toBe(
            HILOS_CONNECTION_STATUS_TEXT_ID,
          )
        },
      )
    })

    it('C04 keeps both live explanations on a disabled takeover toggle', async () => {
      await withThemeMenu(
        {
          account: true,
          shell: true,
          beforeMount(server) {
            server.impersonate()
            server.state('disconnected')
          },
        },
        async (view) => {
          expect(toggle(view).disabled).toBe(true)
          expect(toggle(view).getAttribute('aria-describedby')).toBe(
            `${HILOS_IMPERSONATION_STRIP_TEXT_ID} ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
          )
          expect(
            view.root.querySelector(`#${HILOS_IMPERSONATION_STRIP_TEXT_ID}`),
          ).not.toBeNull()
          expect(
            view.root.querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
          ).not.toBeNull()
        },
      )
    })

    it('C05 keeps a pending choice busy through connecting and reconnecting', async () => {
      await withThemeMenu({ account: true, shell: true }, async (view) => {
        await click(view, toggle(view))
        await click(view, row(view, 'light'))
        expect(toggle(view).disabled).toBe(true)
        expect(toggle(view).getAttribute('aria-busy')).toBe('true')
        await view.update(() => view.server.state('connecting'))
        expect(toggle(view).getAttribute('aria-describedby')).toBe(
          HILOS_CONNECTION_STATUS_TEXT_ID,
        )
        await view.update(() => view.server.state('connected'))
        expect(toggle(view).disabled).toBe(true)
        expect(toggle(view).getAttribute('aria-busy')).toBe('true')
        expect(toggle(view).getAttribute('aria-describedby')).toBeNull()
        await view.advance(300)
        expect(
          view.root.querySelector('[data-id="nav-theme-loading"]'),
        ).not.toBeNull()
        await view.update(() => view.server.reply('success'))
        await view.settle()
        expect(toggle(view).disabled).toBe(false)
        confirmed('dark')
      })
    })

    it('C06 uses the readonly false connection fallback outside the shell', async () => {
      await withThemeMenu(
        {
          account: true,
          beforeMount: (server) => server.state('disconnected'),
        },
        async (view) => {
          expect(toggle(view).disabled).toBe(false)
          expect(toggle(view).getAttribute('aria-describedby')).toBeNull()
          expect(
            view.root.querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
          ).toBeNull()
          await click(view, toggle(view))
          expect(row(view, 'light').disabled).toBe(false)
        },
      )
    })

    it('C07 names each live position and its decorative icon', async () => {
      await withThemeMenu(
        {
          pick: 'light',
          settings: { switchingEnabled: true, defaultTheme: 'system' },
        },
        async (view) => {
          for (const [pick, name] of [
            ['light', 'Light'],
            ['dark', 'Dark'],
            ['system', 'System'],
          ] as const) {
            await view.update(() => setHilosThemePick(pick))
            expect(toggle(view).getAttribute('data-position')).toBe(pick)
            expect(toggle(view).title).toBe(`Theme: ${name}`)
            expect(toggle(view).getAttribute('aria-label')).toBe(
              `Theme: ${name}`,
            )
            expect(toggle(view).getAttribute('aria-haspopup')).toBe('menu')
            expect(
              toggle(view).querySelector('i')?.getAttribute('aria-hidden'),
            ).toBe('true')
          }
        },
      )
    })

    it('C08 relates and names the menu, marking only the default until a personal choice', async () => {
      await withThemeMenu(
        {
          pick: null,
          settings: { switchingEnabled: true, defaultTheme: 'dark' },
        },
        async (view) => {
          await click(view, toggle(view))
          const opened = menu(view)!
          expect(opened.id).toBe(toggle(view).getAttribute('aria-controls'))
          expect(opened.getAttribute('role')).toBe('menu')
          expect(opened.getAttribute('aria-label')).toBe('Theme')
          checked(view, 'dark')
          expect(row(view, 'dark').textContent).toContain('default')
          expect(row(view, 'light').textContent).not.toContain('default')
          await view.update(() => setHilosThemePick('dark'))
          expect(row(view, 'dark').textContent).not.toContain('default')
          checked(view, 'dark')
        },
      )
    })

    it('C09 hides the control while switching is off and retains the remembered pick on re-enable', async () => {
      await withThemeMenu({ settings: off }, async (view) => {
        expect(view.root.querySelector('[data-id="nav-theme"]')).toBeNull()
        expect(menu(view)).toBeNull()
        confirmed('dark')
        await view.update(() => view.server.snapshot(enabled, 'dark'))
        expect(toggle(view).getAttribute('data-position')).toBe('dark')
        closed(view)
      })
    })

    it('C10 redraws the checked rows and position without closing an open menu', async () => {
      await withThemeMenu({}, async (view) => {
        await click(view, toggle(view))
        expect(toggle(view).getAttribute('aria-expanded')).toBe('true')
        checked(view, 'dark')
        await view.update(() => setHilosThemePick('system'))
        expect(toggle(view).getAttribute('aria-expanded')).toBe('true')
        expect(toggle(view).getAttribute('data-position')).toBe('system')
        expect(toggle(view).getAttribute('aria-label')).toBe('Theme: System')
        checked(view, 'system')
      })
    })

    it('C11 roves all four controls, wraps, and returns focus on either Escape path', async () => {
      await withThemeMenu(
        {
          pick: null,
          settings: { switchingEnabled: true, defaultTheme: 'system' },
        },
        async (view) => {
          await press(view, toggle(view), 'ArrowDown')
          await view.keyboardFlush()
          const opened = menu(view)!
          const buttons = [
            ...opened.querySelectorAll<HTMLButtonElement>(
              '[role="menuitemradio"], [role="menuitem"]',
            ),
          ]
          expect(buttons).toHaveLength(4)
          expect(
            opened.querySelectorAll('[role="menuitemradio"]'),
          ).toHaveLength(3)
          expect(document.activeElement).toBe(buttons[0])
          for (const [name, index] of [
            ['ArrowDown', 1],
            ['ArrowUp', 0],
            ['ArrowUp', 3],
            ['ArrowUp', 2],
            ['Home', 0],
            ['End', 3],
            ['ArrowDown', 0],
          ] as const) {
            await press(view, opened, name)
            expect(document.activeElement).toBe(buttons[index])
          }
          await press(view, opened, 'Escape')
          closed(view)
          expect(document.activeElement).toBe(toggle(view))
          await press(view, toggle(view), 'ArrowUp')
          await view.keyboardFlush()
          expect(document.activeElement).toBe(row(view, null))
          await press(view, toggle(view), 'Escape')
          closed(view)
          expect(document.activeElement).toBe(toggle(view))
        },
      )
    })

    it('C12 dismisses only an outside click and preserves its focus', async () => {
      await withThemeMenu({}, async (view) => {
        await click(view, toggle(view))
        await click(view, menu(view)!)
        expect(toggle(view).getAttribute('aria-expanded')).toBe('true')
        const outside = focusElsewhere(view)
        await click(view, document.body)
        closed(view)
        expect(document.activeElement).toBe(outside)
      })
    })

    it.each([
      ['light', 'light', 'Light'],
      ['dark', 'dark', 'Dark'],
      ['system', 'light', 'System'],
    ] as const)(
      'C13 applies and remembers guest %s with its position, mode and checked mark',
      async (pick, mode, name) => {
        await withThemeMenu({ pick: null }, async (view) => {
          await click(view, toggle(view))
          await click(view, row(view, pick))
          confirmed(pick)
          expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
            mode,
          )
          expect(toggle(view).getAttribute('data-position')).toBe(pick)
          expect(toggle(view).getAttribute('aria-label')).toBe(`Theme: ${name}`)
          closed(view)
          expect(document.activeElement).toBe(toggle(view))
          expect(hilosToasts.toasts.get()).toEqual([])
          await click(view, toggle(view))
          checked(view, pick)
        })
      },
    )

    it('C14 makes the current default light personal without changing the worn mode', async () => {
      await withThemeMenu({ pick: null }, async (view) => {
        await click(view, toggle(view))
        expect(row(view, 'light').textContent).toContain('default')
        await click(view, row(view, 'light'))
        confirmed('light')
        await click(view, toggle(view))
        expect(row(view, 'light').textContent).not.toContain('default')
        checked(view, 'light')
      })
    })

    it('C15 always offers a separate enabled reset and removes only the guest pick, twice', async () => {
      await withThemeMenu({}, async (view) => {
        for (let attempt = 0; attempt < 2; attempt++) {
          await click(view, toggle(view))
          const opened = menu(view)!
          const reset = row(view, null)
          expect(
            opened.querySelectorAll('[role="menuitemradio"]'),
          ).toHaveLength(3)
          expect(opened.querySelectorAll('[role="separator"]')).toHaveLength(1)
          expect(reset.previousElementSibling?.getAttribute('role')).toBe(
            'separator',
          )
          expect(reset.textContent?.trim()).toBe('Use site default')
          expect(reset.getAttribute('role')).toBe('menuitem')
          expect(reset.hasAttribute('aria-checked')).toBe(false)
          expect(reset.querySelector('i')).toBeNull()
          expect(reset.disabled).toBe(false)
          expect(shown(reset)).toBe(true)
          await click(view, reset)
          confirmed(null)
          expect(
            localStorage.getItem(THEME_SETTINGS_STORAGE_KEY),
          ).not.toBeNull()
          expect(toggle(view).getAttribute('data-position')).toBe('light')
          closed(view)
          expect(document.activeElement).toBe(toggle(view))
          await click(view, toggle(view))
          expect(row(view, 'light').textContent).toContain('default')
          await press(view, menu(view)!, 'Escape')
        }
      })
    })

    it.each(['light', 'dark', 'system', null] as const)(
      'C16 sends account %s exactly once and waits for authoritative choice after its own reply',
      async (pick) => {
        await withThemeMenu({ account: true }, async (view) => {
          const { server } = view
          await click(view, toggle(view))
          const opened = menu(view)!
          await click(view, row(view, pick))
          expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
            'hilos_theme_pick',
            { themePick: pick },
            '1',
          )
          confirmed('dark')
          closed(view)
          expect(toggle(view).disabled).toBe(true)
          expect(toggle(view).getAttribute('aria-busy')).toBe('true')
          expect(opened.contains(document.activeElement)).toBe(false)
          expect(
            view.root.querySelector('[data-id="nav-theme-loading"]'),
          ).toBeNull()
          await press(view, toggle(view), 'ArrowDown')
          closed(view)
          const outside = focusElsewhere(view)
          expect(document.activeElement).toBe(outside)
          const focus = vi.spyOn(toggle(view), 'focus')
          await view.update(() => server.reply('success', 'another-request'))
          await view.settle()
          expect(toggle(view).disabled).toBe(true)
          expect(focus).not.toHaveBeenCalled()
          await view.update(() => server.reply('success'))
          await view.settle()
          confirmed('dark')
          expect(toggle(view).disabled).toBe(false)
          expect(toggle(view).hasAttribute('aria-busy')).toBe(false)
          expect(focus).toHaveBeenCalledOnce()
          expect(document.activeElement).toBe(toggle(view))
          await view.update(() => server.handshake(pick, 1, true))
          expect(toggle(view).getAttribute('data-position')).toBe(
            pick ?? 'light',
          )
          confirmed(pick)
          expect(hilosToasts.toasts.get()).toEqual([])
        })
      },
    )

    it.each(['frame-first', 'reply-first'] as const)(
      'C17 makes the account default personal with %s, repeats it, then signs out to guest choices',
      async (order) => {
        await withThemeMenu({ account: true, pick: null }, async (view) => {
          const { server } = view
          await click(view, toggle(view))
          const light = row(view, 'light')
          expect(light.textContent).toContain('default')
          await click(view, light)
          expect(server.sendAction).toHaveBeenCalledOnce()
          confirmed(null)
          expect(light.textContent).toContain('default')
          if (order === 'frame-first') {
            // Vue's retained closed row proved frame-before-ack in the source.
            await view.update(() => server.handshake('light'))
            await view.update(() => server.reply('success'))
            await view.settle()
          } else {
            await view.update(() => server.reply('success'))
            await view.settle()
            await click(view, toggle(view))
            expect(row(view, 'light').textContent).toContain('default')
            await press(view, menu(view)!, 'Escape')
            await view.update(() => server.handshake('light'))
          }
          await click(view, toggle(view))
          expect(row(view, 'light').textContent).not.toContain('default')
          await click(view, row(view, 'light'))
          expect(server.sendAction).toHaveBeenCalledTimes(2)
          await view.update(() => server.reply('success'))
          await view.settle()
          await view.update(() => server.handshake('light', null))
          await click(view, toggle(view))
          await click(view, row(view, 'dark'))
          confirmed('dark')
          expect(server.sendAction).toHaveBeenCalledTimes(2)
        })
      },
    )

    it.each([
      ['fail', 'dark'],
      ['timeout', 'dark'],
      ['disconnected', 'dark'],
      ['fail', null],
      ['timeout', null],
      ['disconnected', null],
    ] as const)(
      'C18 keeps confirmed state through delayed loading and %s for %s, ignoring a late reply',
      async (outcome, pick) => {
        await withThemeMenu({ account: true, pick: 'light' }, async (view) => {
          const { server } = view
          await click(view, toggle(view))
          const system = row(view, 'system')
          await click(view, row(view, pick))
          await view.advance(299)
          expect(
            view.root.querySelector('[data-id="nav-theme-loading"]'),
          ).toBeNull()
          await view.advance(1)
          const loading = view.root.querySelector(
            '[data-id="nav-theme-loading"]',
          )
          expect(loading).not.toBeNull()
          expect(loading?.getAttribute('role')).toBe('status')
          expect(loading?.textContent).toContain('Loading')
          expect(shown(toggle(view).querySelector('i'))).toBe(false)
          expect(toggle(view).getAttribute('aria-busy')).toBe('true')
          focusElsewhere(view)
          await view.update(() => {
            toggle(view).click()
            // Retained Vue rows receive this native event even while disabled.
            system.dispatchEvent(new MouseEvent('click', { bubbles: true }))
          })
          closed(view)
          expect(server.sendAction).toHaveBeenCalledOnce()
          if (outcome === 'fail') await view.update(() => server.reply('fail'))
          else if (outcome === 'timeout') await view.advance(29_700)
          else await view.update(() => server.state('reconnecting'))
          await view.settle()
          confirmed('light')
          expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
            'light',
          )
          expect(toggle(view).disabled).toBe(false)
          expect(document.activeElement).toBe(toggle(view))
          expect(
            view.root.querySelector('[data-id="nav-theme-loading"]'),
          ).toBeNull()
          expect(shown(toggle(view).querySelector('i'))).toBe(true)
          const messages = {
            fail: 'Theme switching is disabled.',
            timeout: 'The action timed out. Please try again.',
            disconnected:
              'The connection dropped before the action completed. Please try again.',
          }
          expect(hilosToasts.toasts.get()).toMatchObject([
            { message: messages[outcome], severity: 'error' },
          ])
          focusElsewhere(view)
          const focus = vi.spyOn(toggle(view), 'focus')
          await view.update(() => server.state('connected'))
          await view.update(() => server.reply('success', '1'))
          await view.settle()
          confirmed('light')
          closed(view)
          expect(focus).not.toHaveBeenCalled()
          await click(view, toggle(view))
          await click(view, row(view, 'system'))
          expect(server.sendAction).toHaveBeenCalledTimes(2)
          await view.update(() => server.reply('success'))
          await view.settle()
        })
      },
    )

    it.each(['off', 'signout', 'impersonation'] as const)(
      'C19 checks raw %s state before a stale row click in the same native turn',
      async (change) => {
        await withThemeMenu({ account: true }, async (view) => {
          await click(view, toggle(view))
          const light = row(view, 'light')
          await view.update(() => {
            changeSession(view.server, change, 'dark')
            light.click()
          })
          expect(view.server.sendAction).not.toHaveBeenCalled()
          confirmed(change === 'signout' ? 'light' : 'dark')
          if (change === 'off')
            expect(view.root.querySelector('[data-id="nav-theme"]')).toBeNull()
          else closed(view)
        })
      },
    )

    it.each(['off', 'signout', 'impersonation'] as const)(
      'C20 never refocuses, reopens or applies late success after %s',
      async (change) => {
        await withThemeMenu({ account: true, pick: 'light' }, async (view) => {
          await click(view, toggle(view))
          await click(view, row(view, 'dark'))
          const button = toggle(view)
          focusElsewhere(view)
          const focus = vi.spyOn(button, 'focus')
          await view.update(() => changeSession(view.server, change, 'light'))
          await view.update(() => view.server.reply('success'))
          await view.settle()
          confirmed('light')
          expect(view.server.sendAction).toHaveBeenCalledOnce()
          expect(focus).not.toHaveBeenCalled()
          expect(document.activeElement).not.toBe(button)
          if (change !== 'off') closed(view)
        })
      },
    )

    it.each(['success', 'fail'] as const)(
      'C21 never focuses or applies locally after an unmounted action returns %s',
      async (outcome) => {
        await withThemeMenu({ account: true, pick: 'light' }, async (view) => {
          await click(view, toggle(view))
          await click(view, row(view, 'dark'))
          const button = toggle(view)
          focusElsewhere(view)
          const focus = vi.spyOn(button, 'focus')
          await view.unmount()
          await view.update(() => view.server.reply(outcome))
          // Angular's adapter ticks ApplicationRef here, after destruction/result.
          await view.settle()
          expect(button.isConnected).toBe(false)
          expect(focus).not.toHaveBeenCalled()
          confirmed('light')
          expect(view.server.sendAction).toHaveBeenCalledOnce()
        })
      },
    )

    it('C22 keeps newer server authority over a keyboard-submitted action that succeeds late', async () => {
      await withThemeMenu({ account: true, pick: 'light' }, async (view) => {
        await press(view, toggle(view), 'ArrowUp')
        await view.keyboardFlush()
        const opened = menu(view)!
        expect(document.activeElement).toBe(row(view, null))
        await press(view, opened, 'ArrowUp')
        expect(document.activeElement).toBe(row(view, 'system'))
        // Native buttons turn Enter/Space into this click in a browser.
        await click(view, document.activeElement as HTMLButtonElement)
        expect(view.server.sendAction).toHaveBeenCalledWith(
          'hilos_theme_pick',
          { themePick: 'system' },
          '1',
        )
        closed(view)
        expect(toggle(view).disabled).toBe(true)
        expect(toggle(view).getAttribute('aria-busy')).toBe('true')
        expect(opened.contains(document.activeElement)).toBe(false)
        await view.update(() => view.server.handshake('dark'))
        await view.update(() => view.server.reply('success'))
        await view.settle()
        confirmed('dark')
        expect(toggle(view).getAttribute('data-position')).toBe('dark')
      })
    })

    it('C23 refuses a stale guest row on raw switching-off and does not reopen after a new binding', async () => {
      await withThemeMenu({}, async (view) => {
        await click(view, toggle(view))
        const light = row(view, 'light')
        await view.update(() => {
          view.server.snapshot(off, 'dark')
          light.click()
        })
        confirmed('dark')
        expect(
          view.root.querySelector('[data-id="nav-theme-option-light"]'),
        ).toBeNull()
        await view.update(() => view.server.snapshot(enabled, 'dark'))
        closed(view)
      })
    })

    it('C24 still applies and closes a guest choice when browser storage refuses every operation', async () => {
      await withThemeMenu({ pick: null }, async (view) => {
        view.server.refuseStorage()
        await click(view, toggle(view))
        await click(view, row(view, 'dark'))
        expect(hilosThemePick.get()).toBe('dark')
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
          'dark',
        )
        expect(toggle(view).getAttribute('data-position')).toBe('dark')
        closed(view)
        expect(document.activeElement).toBe(toggle(view))
      })
    })

    it('C25 submits only dark for native dark/reset/dark clicks before the next redraw', async () => {
      await withThemeMenu({ account: true, pick: 'light' }, async (view) => {
        await click(view, toggle(view))
        const dark = row(view, 'dark')
        const reset = row(view, null)
        await view.update(() => {
          dark.click()
          reset.click()
          dark.click()
        })
        expect(view.server.sendAction).toHaveBeenCalledExactlyOnceWith(
          'hilos_theme_pick',
          { themePick: 'dark' },
          '1',
        )
        await view.update(() => view.server.reply('success'))
        await view.settle()
        expect(view.server.sendAction).toHaveBeenCalledOnce()
      })
    })

    it('C26 keeps a takeover icon visible, natively disabled and described, refusing keyboard opening', async () => {
      await withThemeMenu({ account: true }, async (view) => {
        await view.update(() => view.server.impersonate())
        expect(shown(toggle(view))).toBe(true)
        expect(toggle(view).disabled).toBe(true)
        expect(toggle(view).getAttribute('aria-describedby')).toBe(
          HILOS_IMPERSONATION_STRIP_TEXT_ID,
        )
        await press(view, toggle(view), 'ArrowDown')
        await view.keyboardFlush()
        closed(view)
        expect(view.server.sendAction).not.toHaveBeenCalled()
      })
    })

    it('C27 releases its outside-click listener when the open menu unmounts', async () => {
      await withThemeMenu({}, async (view) => {
        await click(view, toggle(view))
        const remove = vi.spyOn(document, 'removeEventListener')
        await view.unmount()
        expect(remove.mock.calls.some(([type]) => type === 'click')).toBe(true)
      })
    })

    it('C28a closes an open menu on sign-out and returns focus to the icon', async () => {
      await withThemeMenu({ account: true }, async (view) => {
        await click(view, toggle(view))
        expect(shown(menu(view))).toBe(true)
        await view.update(() => view.server.handshake('dark', null))
        closed(view)
        expect(document.activeElement).toBe(toggle(view))
      })
    })

    it('C28b closes a keyboard-focused menu on takeover with focus outside its closed surface', async () => {
      await withThemeMenu({ account: true }, async (view) => {
        await press(view, toggle(view), 'ArrowDown')
        await view.keyboardFlush()
        const opened = menu(view)!
        expect(shown(opened)).toBe(true)
        expect(document.activeElement).toBe(row(view, 'light'))
        await view.update(() => view.server.impersonate())
        closed(view)
        expect(toggle(view).disabled).toBe(true)
        expect(
          toggle(view).getAttribute('aria-describedby')?.split(/\s+/),
        ).toContain(HILOS_IMPERSONATION_STRIP_TEXT_ID)
        expect(opened.contains(document.activeElement)).toBe(false)
      })
    })
  })
}
