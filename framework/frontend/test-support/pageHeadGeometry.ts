// Controlled DOM inputs for PageHead; scroll expectations belong to its cases.
import type { Mock, vi as VitestVi } from 'vitest'

export const PAGE_HEAD_ROW = '[data-id="hilos-page-tabs"] ul'
const TAB_PREFIX = 'hilos-page-tab-'
const ROW_LEFT = 20
const WIDTHS: Record<string, number> = {
  demo_section: 100,
  demo_section_list: 60,
  demo_section_gaps: 80,
  demo_section_gaps_machine: 120,
}

/** The actual callback, observed targets and disconnects of one observer. */
interface PageHeadObserver {
  resize: () => void
  targets: Element[]
  disconnect: Mock
}

/** Install before mounting: React measures during its first layout effect. */
export function installPageHeadGeometry(
  vi: Pick<typeof VitestVi, 'spyOn' | 'fn'>,
) {
  const box = { width: 200, content: 360 }
  const observers: PageHeadObserver[] = []
  const restorers: (() => void)[] = []
  const resizeDescriptor = Object.getOwnPropertyDescriptor(
    globalThis,
    'ResizeObserver',
  )
  const widthOf = (link: HTMLElement) =>
    WIDTHS[(link.dataset['id'] ?? '').slice(TAB_PREFIX.length)] ?? 0

  function restore(): void {
    for (const restore of restorers.reverse()) restore()
  }

  try {
    // HTMLElement owns clientWidth in happy-dom and inherits it in jsdom.
    const clientWidth = vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get')
    restorers.push(() => clientWidth.mockRestore())
    clientWidth.mockImplementation(function (this: HTMLElement) {
      return this.matches(PAGE_HEAD_ROW) ? box.width : 0
    })
    const scrollWidth = vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get')
    restorers.push(() => scrollWidth.mockRestore())
    scrollWidth.mockImplementation(function (this: HTMLElement) {
      return this.matches(PAGE_HEAD_ROW) ? box.content : 0
    })
    const bounds = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    restorers.push(() => bounds.mockRestore())
    bounds.mockImplementation(function (this: HTMLElement) {
      if (this.matches(PAGE_HEAD_ROW)) {
        return { left: ROW_LEFT, width: box.width } as DOMRect
      }
      const row = this.closest<HTMLElement>(PAGE_HEAD_ROW)
      if (row === null) return { left: 0, width: 0 } as DOMRect
      const links = [...row.querySelectorAll<HTMLElement>('a')]
      const offset = links
        .slice(0, links.indexOf(this))
        .reduce((sum, other) => sum + widthOf(other), 0)
      return {
        left: ROW_LEFT + offset - row.scrollLeft,
        width: widthOf(this),
      } as DOMRect
    })
    restorers.push(() => {
      if (resizeDescriptor === undefined) {
        Reflect.deleteProperty(globalThis, 'ResizeObserver')
      } else {
        Object.defineProperty(globalThis, 'ResizeObserver', resizeDescriptor)
      }
    })
    Object.defineProperty(globalThis, 'ResizeObserver', {
      configurable: true,
      writable: true,
      value: class implements PageHeadObserver {
        resize: () => void
        targets: Element[] = []
        disconnect = vi.fn()
        constructor(callback: () => void) {
          this.resize = callback
          observers.push(this)
        }
        observe(target: Element) {
          this.targets.push(target)
        }
        unobserve() {}
      },
    })
    return { box, observers, restore }
  } catch (error) {
    restore()
    throw error
  }
}
