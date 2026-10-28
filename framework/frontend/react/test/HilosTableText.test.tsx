import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { StrictMode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HIDDEN_VALUE,
  TableViewportController,
  type Hideable,
  type HilosTableRowHeight,
  type TableProjection,
} from '@hilos/core/base'
import { HilosTableText } from '../src/HilosTableText.js'
import { HilosTableValue } from '../src/HilosTableValue.js'

const owners: { dispose(): void }[] = []
afterEach(() => {
  cleanup()
  for (const owner of owners.splice(0)) owner.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

function owner(policy: HilosTableRowHeight = { mode: 'lines', count: 2 }) {
  const controller = new TableViewportController<unknown>({
    resolve: (row) => row.slots,
    sendViewport: () => {},
    frame: {
      columns: [
        { key: 'name', label: 'Name', heightContent: 'parts' },
        { key: 'other', label: 'Other', heightContent: 'parts' },
      ],
      rowHeight: policy,
    },
  })
  controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1, true, null, null, 10)
  const registration = controller.geometry.register(() => ({ records: [] }))
  owners.push(registration, controller)
  const records = {
    wide: registration.measurement('a', 'wide')!.record,
    card: registration.measurement('a', 'card')!.record,
  }
  return { controller, records }
}

// Synthetic boxes prove timing, identity and focus; browser tests own native layout.
function layout() {
  const frames = new Map<number, FrameRequestCallback>()
  const history: FrameRequestCallback[] = []
  const observers: { nodes: Set<Element>; callback: () => void }[] = []
  let serial = 0
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    frames.set(++serial, callback)
    history.push(callback)
    return serial
  })
  vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
  vi.stubGlobal(
    'ResizeObserver',
    class {
      readonly nodes = new Set<Element>()
      constructor(readonly callback: () => void) {
        observers.push(this)
      }
      observe(node: Element) {
        this.nodes.add(node)
      }
      disconnect() {
        this.nodes.clear()
      }
    },
  )
  const heights = { wide: 80, card: 80 }
  const widths = { wide: 200, card: 200 }
  function projection(node: Element): TableProjection {
    return node
      .closest('[data-projection]')
      ?.getAttribute('data-projection') === 'card'
      ? 'card'
      : 'wide'
  }
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      return new DOMRect(0, 0, widths[projection(this)], 40)
    },
  )
  for (const property of [
    'clientWidth',
    'clientHeight',
    'scrollWidth',
    'scrollHeight',
  ] as const)
    vi.spyOn(HTMLElement.prototype, property, 'get').mockImplementation(
      function (this: HTMLElement) {
        if (property === 'clientHeight') return 40
        if (property === 'scrollHeight') return heights[projection(this)]
        return widths[projection(this)]
      },
    )
  return {
    heights,
    widths,
    history,
    observers,
    async flush() {
      await act(async () => {
        const pending = [...frames.values()]
        frames.clear()
        for (const callback of pending) callback(0)
      })
    },
    resize(node: Element) {
      act(() => {
        for (const observer of observers)
          if (observer.nodes.has(node)) observer.callback()
      })
    },
  }
}

function fixture(
  options: {
    policy?: HilosTableRowHeight
    projections?: TableProjection[]
    columns?: string[]
    regions?: number
    heightContent?: 'text' | 'parts'
    strict?: boolean
  } = {},
) {
  const initial = owner(options.policy)
  let current = initial
  let value: Hideable<string> = 'Full live text'
  let regions = options.regions ?? 1
  const parentClick = vi.fn()
  const parentKey = vi.fn()
  function App() {
    return (
      <main onClick={parentClick} onKeyDown={parentKey}>
        {(options.projections ?? ['wide', 'card']).flatMap((projection) =>
          (options.columns ?? ['name']).map((columnKey) => {
            const record = current.records[projection]
            return (
              <section
                key={`${projection}:${columnKey}`}
                data-projection={projection}
                data-column={columnKey}
              >
                <HilosTableValue
                  controller={current.controller}
                  readRecord={() =>
                    current.controller.isTextRecordCurrent(record)
                      ? record
                      : null
                  }
                  columnKey={columnKey}
                  label={columnKey === 'name' ? 'Name' : 'Other'}
                  heightContent={options.heightContent ?? 'parts'}
                  projection={projection}
                >
                  <span className="badge">Ready</span>
                  {Array.from({ length: regions }, (_, key) => (
                    <HilosTableText key={key} value={value} />
                  ))}
                  <a href="/next">Link</a>
                  <button type="button" data-id="own-action">
                    Act
                  </button>
                </HilosTableValue>
              </section>
            )
          }),
        )}
      </main>
    )
  }
  const tree = () =>
    options.strict ? (
      <StrictMode>
        <App />
      </StrictMode>
    ) : (
      <App />
    )
  const view = render(tree())
  return {
    ...view,
    ...initial,
    parentClick,
    parentKey,
    setValue(next: Hideable<string>) {
      value = next
      view.rerender(tree())
    },
    setRegions(next: number) {
      regions = next
      view.rerender(tree())
    },
    replace(next = owner()) {
      current = next
      view.rerender(tree())
      return next
    },
  }
}

function controls(container: HTMLElement) {
  return [
    ...container.querySelectorAll<HTMLButtonElement>(
      '[data-id="hilos-table-text-toggle"]',
    ),
  ]
}

describe('HilosTableText', () => {
  it('shares intent between projections with distinct ids and leaves adjacent parts intrinsic', async () => {
    const dom = layout()
    const { container, controller } = fixture()
    await dom.flush()
    const [wide, card] = controls(container)
    expect(wide.type).toBe('button')
    expect(wide.textContent).toBe('Show in full')
    expect(wide.getAttribute('aria-label')).toBe('Show in full: Name')
    expect(wide.getAttribute('aria-expanded')).toBe('false')
    expect(wide.getAttribute('aria-controls')).not.toBe(
      card.getAttribute('aria-controls'),
    )
    for (const button of [wide, card]) {
      const clip = document.getElementById(
        button.getAttribute('aria-controls')!,
      )!
      expect(clip.textContent).toBe('Full live text')
      expect(clip.querySelector('.badge, a, button, [aria-hidden]')).toBeNull()
      expect(clip.closest('[aria-hidden]')).toBeNull()
    }
    fireEvent.click(wide)
    expect(
      controller.expandedText
        .get()
        .get(controller.rows.get()[0].occurrence)
        ?.has('name'),
    ).toBe(true)
    expect(controls(container).map((button) => button.textContent)).toEqual([
      'Collapse',
      'Collapse',
    ])
    expect(
      container.querySelectorAll('.hilos-table-text.is-open'),
    ).toHaveLength(2)
    expect(
      container.querySelectorAll('.badge, a, [data-id="own-action"]'),
    ).toHaveLength(6)
    fireEvent.click(card)
    expect(
      controls(container).map((button) => button.getAttribute('aria-expanded')),
    ).toEqual(['false', 'false'])
  })

  it('isolates click and native activation keys without canceling their default', async () => {
    const dom = layout()
    const view = fixture({ projections: ['wide'] })
    await dom.flush()
    const button = controls(view.container)[0]
    for (const key of ['Enter', ' ']) {
      const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
      })
      fireEvent(button, event)
      expect(event.defaultPrevented).toBe(false)
    }
    expect(view.parentKey).not.toHaveBeenCalled()
    fireEvent.keyDown(button, { key: 'ArrowDown' })
    expect(view.parentKey).toHaveBeenCalledTimes(1)
    fireEvent.click(button)
    expect(view.parentClick).not.toHaveBeenCalled()
    fireEvent.click(view.container.querySelector('[data-id="own-action"]')!)
    expect(view.parentClick).toHaveBeenCalledTimes(1)
  })

  it('preserves the same focused control through shortening, collapse and blur', async () => {
    const dom = layout()
    const view = fixture({ projections: ['wide'] })
    await dom.flush()
    const button = controls(view.container)[0]
    act(() => button.focus())
    fireEvent.click(button)
    dom.heights.wide = 40
    view.setValue('Short')
    await dom.flush()
    expect(controls(view.container)).toEqual([button])
    expect(button.textContent).toBe('Collapse')
    expect(document.activeElement).toBe(button)
    fireEvent.click(button)
    await dom.flush()
    expect(controls(view.container)).toEqual([button])
    expect(button.textContent).toBe('Show in full')
    expect(document.activeElement).toBe(button)
    act(() => button.blur())
    expect(controls(view.container)).toHaveLength(0)
  })

  it('keeps short, empty and zero values in one accessible text node and reserves control room', async () => {
    const dom = layout()
    dom.heights.wide = 40
    const view = fixture({ projections: ['wide'] })
    for (const value of [
      '',
      'Short',
      '0',
      'First\nSecond',
      'UnbrokenToken'.repeat(200),
    ]) {
      view.setValue(value)
      await dom.flush()
      const clip = view.container.querySelector('.hilos-table-text')!
      expect(clip.textContent).toBe(value)
      expect(clip.childNodes).toHaveLength(1)
      expect(clip.closest('[aria-hidden]')).toBeNull()
      expect(controls(view.container)).toHaveLength(0)
      const twin = view.container.querySelector('.invisible')!
      expect(twin.textContent).toBe('Show in full')
      expect(twin.hasAttribute('inert')).toBe(true)
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(
        twin.querySelector('button, a, [id], [data-id], [tabindex]'),
      ).toBeNull()
    }
  })

  it('renders the hidden mark outside an empty inert minimum reserve, then rebinds visible text', async () => {
    const dom = layout()
    const view = fixture({
      projections: ['wide'],
      policy: { mode: 'height', rem: 4.5 },
    })
    view.setValue(HIDDEN_VALUE)
    await dom.flush()
    const reserve = view.container.querySelector(
      '.hilos-table-text-height-4-5',
    )!
    expect(reserve.textContent).toBe('')
    expect(reserve.hasAttribute('inert')).toBe(true)
    expect(reserve.getAttribute('aria-hidden')).toBe('true')
    expect(reserve.classList.contains('is-open')).toBe(true)
    expect(reserve.querySelector('[data-id="hilos-hidden"]')).toBeNull()
    expect(
      view.container.querySelectorAll('[data-id="hilos-hidden"]'),
    ).toHaveLength(1)
    expect(view.container.textContent).not.toContain('[object Object]')
    expect(controls(view.container)).toHaveLength(0)
    view.setValue('Visible')
    await dom.flush()
    expect(view.container.querySelector('[data-id="hilos-hidden"]')).toBeNull()
    expect(controls(view.container)).toHaveLength(1)
  })

  it('keeps content mode natural with no control room or cardinality limit', async () => {
    const dom = layout()
    const view = fixture({
      policy: { mode: 'content' },
      regions: 2,
      projections: ['wide'],
    })
    await dom.flush()
    expect(view.container.querySelectorAll('.hilos-table-text')).toHaveLength(2)
    expect(view.container.querySelector('.invisible')).toBeNull()
    expect(controls(view.container)).toHaveLength(0)
    expect(() => view.setRegions(0)).not.toThrow()
  })

  it.each(
    [0, 2].flatMap((regions) =>
      [false, true].map((strict) => ({ regions, strict })),
    ),
  )('rejects invalid committed regions (%j)', ({ regions, strict }) => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    let failure: unknown
    try {
      fixture({ regions, projections: ['wide'], strict })
    } catch (error) {
      failure = error
    }
    const errors =
      failure instanceof AggregateError ? failure.errors : [failure]
    expect(errors).toHaveLength(strict ? 2 : 1)
    for (const error of errors) {
      expect(error).toBeInstanceOf(TypeError)
      expect((error as Error).message).toBe(
        'HilosTableValue: limited parts requires exactly one HilosTableText.',
      )
    }
  })

  it('rejects removal or addition of a committed region', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const view = fixture({ projections: ['wide'], strict: true })
    expect(() => view.setRegions(0)).toThrow(TypeError)
    const next = fixture({ projections: ['wide'], strict: true })
    expect(() => next.setRegions(2)).toThrow(TypeError)
  })

  it('requires parts context, including after a mode change', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    expect(() => render(<HilosTableText value="Text" />)).toThrow(
      'requires a heightContent: parts context',
    )
    expect(() => fixture({ heightContent: 'text' })).toThrow(TypeError)
  })

  it('does not duplicate committed regions under StrictMode', async () => {
    const dom = layout()
    const view = fixture({ strict: true })
    await dom.flush()
    expect(controls(view.container)).toHaveLength(2)
    view.setValue('New live text')
    await dom.flush()
    expect(controls(view.container)).toHaveLength(2)
    fireEvent.click(controls(view.container)[0])
    expect(
      controls(view.container).every(
        (button) => button.textContent === 'Collapse',
      ),
    ).toBe(true)
  })

  it('keeps columns and separate tables independent', async () => {
    const dom = layout()
    const first = fixture({ columns: ['name', 'other'] })
    const second = fixture()
    await dom.flush()
    fireEvent.click(controls(first.container)[0])
    expect(
      controls(first.container).map((button) => button.textContent),
    ).toEqual(['Collapse', 'Show in full', 'Collapse', 'Show in full'])
    expect(
      controls(second.container).map((button) => button.textContent),
    ).toEqual(['Show in full', 'Show in full'])
    const ids = [
      ...controls(first.container),
      ...controls(second.container),
    ].map((button) => button.getAttribute('aria-controls'))
    expect(new Set(ids).size).toBe(6)
  })

  it('binds hidden zero projections on visibility change without clearing intent', async () => {
    const dom = layout()
    dom.widths.card = 0
    const view = fixture()
    await dom.flush()
    expect(controls(view.container)).toHaveLength(1)
    fireEvent.click(controls(view.container)[0])
    expect(
      controls(view.container).map((button) => button.textContent),
    ).toEqual(['Collapse', 'Collapse'])
    dom.widths.card = 200
    fireEvent(window, new Event('resize'))
    await dom.flush()
    fireEvent.click(controls(view.container)[1])
    await dom.flush()
    expect(
      controls(view.container).map((button) => button.textContent),
    ).toEqual(['Show in full', 'Show in full'])
  })

  it('observes natural typography changes inside an unchanged rem window', async () => {
    const dom = layout()
    dom.heights.wide = 40
    const view = fixture({
      projections: ['wide'],
      policy: { mode: 'height', rem: 4.5 },
    })
    await dom.flush()
    expect(controls(view.container)).toHaveLength(0)
    const clip = view.container.querySelector('.hilos-table-text')!
    expect(
      dom.observers.some(
        (observer) =>
          observer.nodes.has(clip) &&
          observer.nodes.has(clip.firstElementChild!),
      ),
    ).toBe(true)
    dom.heights.wide = 80
    dom.resize(clip.firstElementChild!)
    await dom.flush()
    expect(controls(view.container)).toHaveLength(1)
  })

  it('rejects saved commands and queued callbacks across a controller replacement, empty-intent reset and unmount', async () => {
    const dom = layout()
    const view = fixture({ projections: ['wide'] })
    await dom.flush()
    const old = controls(view.container)[0]
    const command = vi.spyOn(view.controller, 'expandText')
    const queued = [...dom.history]
    const next = view.replace()
    await dom.flush()
    fireEvent.click(old)
    for (const callback of queued) callback(0)
    expect(command).not.toHaveBeenCalled()
    expect(next.controller.expandedText.get().size).toBe(0)
    expect(controls(view.container)).toHaveLength(1)
    act(() => next.controller.resetWindowRequests())
    view.setValue('After reset')
    await dom.flush()
    expect(controls(view.container)).toHaveLength(0)
    view.unmount()
    act(() => {
      for (const callback of dom.history) callback(0)
    })
    expect(view.container.textContent).toBe('')
  })
})
