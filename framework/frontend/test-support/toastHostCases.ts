// One semantic union, exercised by all three SDKs' real ToastHost components.
import type { HilosToastStore, HilosToastViewer } from '@hilos/core/base'

import {
  byId,
  overfill,
  sessionToast,
  text,
  withToastCase,
  type ToastHostRunner,
  type ToastHostView,
  type WithToastHost,
} from './toastHostFixtures.js'

/** Fill the stack with a notice whose expiry the host can pause. */
function saved(store: HilosToastStore): void {
  store.push('Saved', { severity: 'success' })
}

/** Report actual occupied height so the third card exceeds the viewport budget. */
function measuredPair(runner: ToastHostRunner, store: HilosToastStore): void {
  runner.vi
    .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    .mockReturnValue(new DOMRect(0, 0, 100, 100))
  store.push('One', { severity: 'info' })
  store.push('Two', { severity: 'info' })
}

/** A later notice must get a running countdown after the earlier hold is gone. */
async function pushAndExpire(
  runner: ToastHostRunner,
  view: ToastHostView,
  store: HilosToastStore,
): Promise<void> {
  await view.flush(() => saved(store))
  await view.flush(() => runner.vi.advanceTimersByTime(20_000))
}

/** Change only the browser's visibility source and deliver its native event. */
async function switchTab(view: ToastHostView, hidden: boolean): Promise<void> {
  await view.flush(() => {
    Object.defineProperty(document, 'hidden', {
      configurable: true,
      get: () => hidden,
    })
    document.dispatchEvent(new Event('visibilitychange'))
  })
}

/** Register content, admission, countdown holds, overflow and accessibility. */
export function registerToastHostCases(
  sdk: string,
  runner: ToastHostRunner,
  withHost: WithToastHost,
): void {
  const { describe, it, expect, vi } = runner
  const run = (
    fill: (store: HilosToastStore) => void,
    steps: (
      view: ToastHostView,
      store: HilosToastStore,
      visited: string[],
    ) => Promise<void>,
  ) => withToastCase(runner, withHost, fill, steps)

  describe(`HilosToastHost on ${sdk}`, () => {
    it('renders every severity with its message and a decorative icon', async () => {
      await run(
        (store) => {
          for (const severity of [
            'error',
            'success',
            'warning',
            'info',
          ] as const) {
            store.push(`${severity} happened`, { severity })
          }
        },
        async ({ root }, store) => {
          expect(store.toasts.get()).toHaveLength(4)
          for (const severity of ['error', 'success', 'warning', 'info']) {
            const card = byId(root, `hilos-toast-${severity}`)
            expect(text(card)).toBe(`${severity} happened`)
            expect(card.querySelector('i')?.getAttribute('aria-hidden')).toBe(
              'true',
            )
          }
          expect(store.toasts.get().every((toast) => toast.measured)).toBe(true)
        },
      )
    })

    it('signs the session notice and leaves the connection notice unsigned', async () => {
      await run(
        (store) => {
          store.syncSession([sessionToast()])
          saved(store)
        },
        async ({ root }) => {
          expect(
            text(byId(byId(root, 'hilos-toast-info'), 'hilos-toast-source')),
          ).toBe('Backup')
          expect(text(byId(root, 'hilos-toast-info').querySelector('a')!)).toBe(
            'The export is ready',
          )
          const own = byId(root, 'hilos-toast-success')
          expect(text(own)).toBe('Saved')
          expect(own.querySelector('[data-id="hilos-toast-source"]')).toBeNull()
        },
      )
    })

    it('counts three identical pushes on one card, with no badge for the first', async () => {
      await run(
        (store) => store.push('Nothing to send', { severity: 'warning' }),
        async (view, store) => {
          expect(
            view.root.querySelector('[data-id="hilos-toast-repeats"]'),
          ).toBeNull()
          await view.flush(() => {
            store.push('Nothing to send', { severity: 'warning' })
            store.push('Nothing to send', { severity: 'warning' })
          })
          expect(
            view.root.querySelectorAll('[data-id="hilos-toast-warning"]'),
          ).toHaveLength(1)
          expect(text(byId(view.root, 'hilos-toast-repeats'))).toBe('×3')
        },
      )
    })

    it('provides a real named link only for a notice with a destination', async () => {
      await run(
        (store) => {
          store.syncSession([sessionToast()])
          saved(store)
        },
        async ({ root }) => {
          const card = byId(root, 'hilos-toast-info')
          expect(card.querySelectorAll('a')).toHaveLength(1)
          const link = card.querySelector('a') as HTMLAnchorElement
          expect(link.getAttribute('href')).toBe('/hilos/backup')
          expect(text(link)).toBe('The export is ready')
          expect(link.tabIndex).toBe(0)
          expect(
            byId(root, 'hilos-toast-success').querySelector('a'),
          ).toBeNull()
        },
      )
    })

    it('answers about the session card whose plain link navigates in place', async () => {
      await run(
        (store) => {
          store.syncSession([sessionToast()])
          saved(store)
        },
        async (view, store, visited) => {
          await view.click(
            byId(view.root, 'hilos-toast-info').querySelector('a')!,
          )
          expect(visited).toEqual(['/hilos/backup'])
          expect(store.dismissedSessionKeys.get()).toEqual(['toast-key'])
          // Closing a session notice awaits the server's authoritative frame.
          expect(store.toasts.get().map((toast) => toast.message)).toEqual([
            'The export is ready',
            'Saved',
          ])
          await view.flush(() => store.syncSession([]))
          expect(
            view.root.querySelector('[data-id="hilos-toast-info"]'),
          ).toBeNull()
          expect(text(byId(view.root, 'hilos-toast-success'))).toBe('Saved')
        },
      )
    })

    it('keeps the session card when a modified click opens another tab', async () => {
      await run(
        (store) => store.syncSession([sessionToast()]),
        async (view, store, visited) => {
          await view.click(
            byId(view.root, 'hilos-toast-info').querySelector('a')!,
            { ctrlKey: true },
          )
          expect(visited).toEqual([])
          expect(store.dismissedSessionKeys.get()).toEqual([])
          expect(store.toasts.get()).toHaveLength(1)
          expect(byId(view.root, 'hilos-toast-info')).not.toBeNull()
        },
      )
    })

    it('renders the unmeasured card before admitting it and starting its life bar', async () => {
      let first:
        | {
            card: boolean
            measured: boolean
            bar: Element | null
            polite: string
            assertive: string
            pixels: number
          }
        | undefined
      await run(
        (store) => {
          saved(store)
          vi.spyOn(
            HTMLElement.prototype,
            'getBoundingClientRect',
          ).mockReturnValue(new DOMRect(0, 0, 100, 100))
          const attach = store.attach.bind(store)
          vi.spyOn(store, 'attach').mockImplementation(() => {
            const viewer = attach()
            const report = viewer.reportHeight.bind(viewer)
            vi.spyOn(viewer, 'reportHeight').mockImplementation(
              (id, pixels) => {
                if (first === undefined) {
                  first = {
                    card:
                      document.querySelector(
                        '[data-id="hilos-toast-success"]',
                      ) !== null,
                    measured: store.toasts.get()[0].measured,
                    bar: document.querySelector('[data-id="hilos-toast-life"]'),
                    polite: text(byId(document, 'hilos-toast-live-polite')),
                    assertive: text(
                      byId(document, 'hilos-toast-live-assertive'),
                    ),
                    pixels,
                  }
                }
                report(id, pixels)
              },
            )
            return viewer
          })
        },
        async ({ root }, store) => {
          expect(first).toEqual({
            card: true,
            measured: false,
            bar: null,
            polite: '',
            assertive: '',
            pixels: 100,
          })
          expect(store.toasts.get()[0].measured).toBe(true)
          const bar = byId(root, 'hilos-toast-life')
          expect(bar.classList.contains('hilos-toast-life-paused')).toBe(false)
          expect(text(byId(root, 'hilos-toast-live-polite'))).toBe('Saved')
        },
      )
    })

    it('measures an error but gives it neither expiry nor a life bar', async () => {
      await run(
        (store) =>
          store.push('The report could not be built', { severity: 'error' }),
        async (view, store) => {
          expect(store.toasts.get()[0].measured).toBe(true)
          expect(
            view.root.querySelector('[data-id="hilos-toast-life"]'),
          ).toBeNull()
          await view.flush(() => vi.advanceTimersByTime(60_000))
          expect(text(byId(view.root, 'hilos-toast-error'))).toBe(
            'The report could not be built',
          )
        },
      )
    })

    it('holds the countdown while the tab is hidden, without calling that reading', async () => {
      await run(saved, async (view, store) => {
        await switchTab(view, true)
        expect(store.reading.get()).toBe(false)
        expect(
          byId(view.root, 'hilos-toast-life').classList.contains(
            'hilos-toast-life-paused',
          ),
        ).toBe(true)
        await view.flush(() => vi.advanceTimersByTime(60_000))
        expect(byId(view.root, 'hilos-toast-success')).not.toBeNull()
        await switchTab(view, false)
        await view.flush(() => vi.advanceTimersByTime(20_000))
        expect(
          view.root.querySelector('[data-id="hilos-toast-success"]'),
        ).toBeNull()
        expect(store.toasts.get()).toEqual([])
      })
    })

    it('holds the countdown when mounted in an already hidden tab without calling that reading', async () => {
      await run(
        (store) => {
          Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => true,
          })
          saved(store)
        },
        async (view, store) => {
          expect(byId(view.root, 'hilos-toast-success')).not.toBeNull()
          expect(store.reading.get()).toBe(false)
          expect(
            byId(view.root, 'hilos-toast-life').classList.contains(
              'hilos-toast-life-paused',
            ),
          ).toBe(true)
          await view.flush(() => vi.advanceTimersByTime(60_000))
          expect(byId(view.root, 'hilos-toast-success')).not.toBeNull()
          expect(store.toasts.get()).toHaveLength(1)
          await switchTab(view, false)
          await view.flush(() => vi.advanceTimersByTime(20_000))
          expect(
            view.root.querySelector('[data-id="hilos-toast-success"]'),
          ).toBeNull()
          expect(store.toasts.get()).toEqual([])
        },
      )
    })

    it('detaches its viewer and stops the countdown when unmounted', async () => {
      await run(saved, async (view, store) => {
        expect(store.toasts.get()[0].measured).toBe(true)
        await view.unmount()
        await view.flush(() => vi.advanceTimersByTime(60_000))
        expect(store.toasts.get()).toHaveLength(1)
      })
    })

    it('takes one cursor hold with its exact reason and pauses the life bar', async () => {
      let viewer: HilosToastViewer | undefined
      await run(
        (store) => {
          saved(store)
          const attach = store.attach.bind(store)
          vi.spyOn(store, 'attach').mockImplementation(() => {
            viewer = attach()
            vi.spyOn(viewer, 'hold')
            vi.spyOn(viewer, 'release')
            return viewer
          })
        },
        async (view, store) => {
          const stack = byId(view.root, 'hilos-toasts')
          await view.mouseOver(stack)
          await view.mouseOver(stack)
          expect(viewer?.hold).toHaveBeenCalledExactlyOnceWith('cursor')
          expect(store.reading.get()).toBe(true)
          expect(
            byId(view.root, 'hilos-toast-life').classList.contains(
              'hilos-toast-life-paused',
            ),
          ).toBe(true)
          await view.flush(() => vi.advanceTimersByTime(60_000))
          expect(byId(view.root, 'hilos-toast-success')).not.toBeNull()
          await view.mouseLeave(stack)
          expect(viewer?.release).toHaveBeenCalledExactlyOnceWith('cursor')
          expect(store.reading.get()).toBe(false)
          expect(
            byId(view.root, 'hilos-toast-life').classList.contains(
              'hilos-toast-life-paused',
            ),
          ).toBe(false)
          await view.flush(() => vi.advanceTimersByTime(20_000))
          expect(store.toasts.get()).toEqual([])
        },
      )
    })

    it('closes a connection notice with its named, keyboard-focusable close button', async () => {
      await run(saved, async (view, store) => {
        const close = byId(view.root, 'hilos-toast-close') as HTMLButtonElement
        expect(close.tagName).toBe('BUTTON')
        expect(close.type).toBe('button')
        expect(close.getAttribute('aria-label')).toBe('Close')
        expect(close.tabIndex).toBe(0)
        await view.click(close)
        expect(
          view.root.querySelector('[data-id="hilos-toast-success"]'),
        ).toBeNull()
        expect(store.toasts.get()).toEqual([])
      })
    })

    it('announces measured errors assertively and signed notices politely outside the stack', async () => {
      let initial: string[] | undefined
      await run(
        (store) => {
          store.push('The report could not be built', { severity: 'error' })
          store.syncSession([sessionToast()])
          const attach = store.attach.bind(store)
          vi.spyOn(store, 'attach').mockImplementation(() => {
            const viewer = attach()
            const report = viewer.reportHeight.bind(viewer)
            vi.spyOn(viewer, 'reportHeight').mockImplementation(
              (id, pixels) => {
                initial ??= [
                  text(byId(document, 'hilos-toast-live-assertive')),
                  text(byId(document, 'hilos-toast-live-polite')),
                ]
                report(id, pixels)
              },
            )
            return viewer
          })
        },
        async ({ root }) => {
          expect(initial).toEqual(['', ''])
          const assertive = byId(root, 'hilos-toast-live-assertive')
          const polite = byId(root, 'hilos-toast-live-polite')
          expect(assertive.getAttribute('role')).toBe('alert')
          expect(assertive.getAttribute('aria-live')).toBe('assertive')
          expect(text(assertive)).toBe('The report could not be built')
          expect(polite.getAttribute('role')).toBe('status')
          expect(polite.getAttribute('aria-live')).toBe('polite')
          expect(text(polite)).toBe('Backup: The export is ready')
          expect(root.querySelectorAll('[aria-live]')).toHaveLength(2)
          const stack = byId(root, 'hilos-toasts')
          expect(stack.querySelector('[aria-live]')).toBeNull()
        },
      )
    })

    it('keeps a connection message from being doubled by the separate live regions', async () => {
      await run(saved, async ({ root }) => {
        const stack = byId(root, 'hilos-toasts')
        expect(stack.querySelector('[aria-live]')).toBeNull()
        expect(text(stack)).toBe('Saved')
      })
    })

    describe('service line', () => {
      it('draws nothing while nothing waits and nothing was missed', async () => {
        await run(saved, async ({ root }, store) => {
          expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
          expect(
            root.querySelector('[data-id="hilos-toast-overflow"]'),
          ).toBeNull()
          expect(
            root.querySelector('[data-id="hilos-toasts"] > div > span > i'),
          ).toBeNull()
        })
      })

      it('keeps waiting plain, missed actionable and the decorative clock before waiting', async () => {
        await run(overfill, async (view, store) => {
          const line = byId(view.root, 'hilos-toast-overflow')
          expect(text(line)).toBe('1 more waiting · 1 missed')
          expect(store.overflow.get()).toEqual({ waiting: 1, missed: 1 })
          expect(line.querySelectorAll('button')).toHaveLength(1)
          const control = byId(line, 'hilos-toast-missed')
          expect(control.tagName).toBe('BUTTON')
          expect(control.getAttribute('type')).toBe('button')
          expect(text(control)).toBe('1 missed')
          expect(control.getAttribute('aria-label')).toBe('1 missed, show one')
          expect(control.tabIndex).toBe(0)
          const icons = line.querySelectorAll('i')
          expect(icons).toHaveLength(1)
          const icon = icons[0]
          expect(icon.getAttribute('aria-hidden')).toBe('true')
          expect(icon.previousSibling).toBeNull()
          expect(icon.nextSibling?.textContent?.trim()).toBe('1 more waiting')
          expect(text(icon.parentElement!)).toBe('1 more waiting')
          expect(icon.parentElement?.tagName).toBe('SPAN')
          expect([...line.children].map((child) => text(child))).toEqual([
            '1 more waiting',
            '·',
            '1 missed',
          ])
          await view.mouseOver(line)
          expect(store.reading.get()).toBe(true)
          await view.mouseLeave(byId(view.root, 'hilos-toasts'))
          expect(store.reading.get()).toBe(false)
        })
      })

      it('returns one missed notice from the actual control', async () => {
        await run(overfill, async (view, store) => {
          await view.click(byId(view.root, 'hilos-toast-missed'))
          expect(store.toasts.get().map((toast) => toast.message)).toContain(
            'Five',
          )
          expect(store.overflow.get()).toEqual({ waiting: 1, missed: 0 })
          expect(
            view.root.querySelector('[data-id="hilos-toast-missed"]'),
          ).toBeNull()
        })
      })

      it('moves keyboard focus to the returned card when the missed control disappears', async () => {
        await run(overfill, async (view) => {
          const control = byId(view.root, 'hilos-toast-missed')
          await view.flush(() => control.focus())
          expect(document.activeElement).toBe(control)
          await view.click(control)
          expect(
            view.root.querySelector('[data-id="hilos-toast-missed"]'),
          ).toBeNull()
          const returned = [
            ...view.root.querySelectorAll('[data-id="hilos-toast-info"]'),
          ].find((card) => text(card).includes('Five'))
          expect(returned).toBeDefined()
          expect(document.activeElement).toBe(
            returned?.querySelector('[data-id="hilos-toast-close"]'),
          )
        })
      })

      it('leaves only waiting text and its decorative clock after the missed notice returns', async () => {
        await run(overfill, async (view, store) => {
          await view.click(byId(view.root, 'hilos-toast-missed'))
          expect(store.overflow.get()).toEqual({ waiting: 1, missed: 0 })
          const line = byId(view.root, 'hilos-toast-overflow')
          expect(text(line)).toBe('1 more waiting')
          const icons = line.querySelectorAll('i')
          expect(icons).toHaveLength(1)
          expect(icons[0].getAttribute('aria-hidden')).toBe('true')
          expect(icons[0].nextSibling?.textContent?.trim()).toBe(
            '1 more waiting',
          )
          expect(line.querySelector('button')).toBeNull()
          expect(text(line)).not.toContain('·')
        })
      })

      it('shows only the named missed control when a third measured card exceeds the budget', async () => {
        await run(
          (store) => measuredPair(runner, store),
          async (view, store) => {
            await view.flush(() => store.push('Three', { severity: 'info' }))
            expect(store.overflow.get()).toEqual({ waiting: 0, missed: 1 })
            const line = byId(view.root, 'hilos-toast-overflow')
            expect(text(line)).toBe('1 missed')
            expect(line.querySelector('i')).toBeNull()
            expect(text(line)).not.toContain('·')
            const control = byId(line, 'hilos-toast-missed')
            expect(control.tagName).toBe('BUTTON')
            expect(control.getAttribute('aria-label')).toBe(
              '1 missed, show one',
            )
            await view.click(control)
            expect(store.toasts.get().map((toast) => toast.message)).toContain(
              'Three',
            )
            expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
            expect(
              view.root.querySelector('[data-id="hilos-toast-overflow"]'),
            ).toBeNull()
          },
        )
      })
    })

    describe('holds follow the visible cards', () => {
      it('does not retake the cursor hold from stale hover after the only card closes', async () => {
        await run(saved, async (view, store) => {
          const stack = byId(view.root, 'hilos-toasts')
          const close = byId(view.root, 'hilos-toast-close')
          vi.spyOn(stack, 'matches').mockReturnValue(true)
          await view.mouseOver(stack)
          await view.click(close)
          await pushAndExpire(runner, view, store)
          expect(store.toasts.get()).toEqual([])
        })
      })

      it('releases the cursor hold when the stack is cleared under a resting cursor', async () => {
        await run(saved, async (view, store) => {
          await view.mouseOver(byId(view.root, 'hilos-toasts'))
          await view.flush(() => store.clear())
          await pushAndExpire(runner, view, store)
          expect(store.toasts.get()).toEqual([])
        })
      })

      it('releases the cursor hold when the server removes the card under it', async () => {
        await run(
          (store) => store.syncSession([sessionToast()]),
          async (view, store) => {
            expect(store.toasts.get()[0].measured).toBe(true)
            await view.mouseOver(byId(view.root, 'hilos-toasts'))
            await view.flush(() => store.syncSession([]))
            await pushAndExpire(runner, view, store)
            expect(store.toasts.get()).toEqual([])
          },
        )
      })

      it('keeps the cursor hold when an unseen notice goes to the missed list', async () => {
        await run(
          (store) => measuredPair(runner, store),
          async (view, store) => {
            await view.mouseOver(byId(view.root, 'hilos-toasts'))
            await view.flush(() => store.push('Three', { severity: 'info' }))
            expect(store.overflow.get().missed).toBe(1)
            await view.flush(() => vi.advanceTimersByTime(20_000))
            expect(store.toasts.get().map((toast) => toast.message)).toEqual([
              'One',
              'Two',
            ])
            expect(store.reading.get()).toBe(true)
          },
        )
      })

      it('releases the focus hold when the stack is cleared under real keyboard focus', async () => {
        await run(saved, async (view, store) => {
          const close = byId(view.root, 'hilos-toast-close')
          await view.flush(() => close.focus())
          expect(document.activeElement).toBe(close)
          expect(store.reading.get()).toBe(true)
          await view.flush(() => store.clear())
          await pushAndExpire(runner, view, store)
          expect(store.toasts.get()).toEqual([])
        })
      })

      it('releases the cursor hold when one card closes while another remains', async () => {
        await run(
          (store) => {
            store.push('Backup failed.', { severity: 'error' })
            saved(store)
          },
          async (view, store) => {
            await view.mouseOver(byId(view.root, 'hilos-toasts'))
            await view.click(
              byId(byId(view.root, 'hilos-toast-error'), 'hilos-toast-close'),
            )
            await view.flush(() => vi.advanceTimersByTime(20_000))
            expect(store.toasts.get()).toEqual([])
            expect(
              view.root.querySelector('[data-id="hilos-toast-success"]'),
            ).toBeNull()
          },
        )
      })

      it('releases the focus hold when its close button removes itself and another card remains', async () => {
        await run(
          (store) => {
            store.push('Backup failed.', { severity: 'error' })
            saved(store)
          },
          async (view, store) => {
            const close = byId(
              byId(view.root, 'hilos-toast-error'),
              'hilos-toast-close',
            )
            await view.flush(() => close.focus())
            expect(store.reading.get()).toBe(true)
            await view.click(close)
            await view.flush(() => vi.advanceTimersByTime(20_000))
            expect(store.toasts.get()).toEqual([])
            expect(
              view.root.querySelector('[data-id="hilos-toast-success"]'),
            ).toBeNull()
          },
        )
      })
    })
  })
}
