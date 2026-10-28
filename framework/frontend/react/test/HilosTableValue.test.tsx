import { act, cleanup, render } from '@testing-library/react'
import {
  StrictMode,
  useLayoutEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HIDDEN_VALUE,
  TableViewportController,
  type HilosTableRowHeight,
} from '@hilos/core/base'
import { HilosHideable } from '../src/HilosHideable.js'
import { HilosTableValue } from '../src/HilosTableValue.js'

const owners: { dispose(): void }[] = []
afterEach(() => {
  cleanup()
  for (const owner of owners.splice(0)) owner.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

function fixture(
  children: ReactNode,
  policy?: HilosTableRowHeight,
  heightContent: 'text' | undefined = 'text',
) {
  const controller = new TableViewportController<unknown>({
    resolve: (row) => row.slots,
    sendViewport: () => {},
    frame: {
      columns: [{ key: 'name', label: 'Name', heightContent: 'text' }],
      ...(policy ? { rowHeight: policy } : {}),
    },
  })
  controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1, true, null, null, 10)
  const registration = controller.geometry.register(() => ({ records: [] }))
  owners.push(registration, controller)
  const record = registration.measurement('a', 'wide')!.record
  const props = {
    controller,
    readRecord: () => (controller.isTextRecordCurrent(record) ? record : null),
    columnKey: 'name',
    label: 'Name',
    heightContent,
    projection: 'wide' as const,
  }
  const view = render(<HilosTableValue {...props}>{children}</HilosTableValue>)
  return { ...view, props, controller }
}

async function settle() {
  await act(async () => {})
}

describe('HilosTableValue', () => {
  it.each([
    undefined,
    { mode: 'content' },
    { mode: 'lines', count: 3 },
    { mode: 'height', rem: 4.5 },
  ] as const)(
    'wraps the first committed DOM before permitting a cap (%j)',
    (policy) => {
      const initial: string[] = []
      function Child() {
        const node = useRef<HTMLSpanElement>(null)
        useLayoutEffect(() => {
          initial.push(node.current!.parentElement!.parentElement!.className)
        }, [])
        return <span ref={node}>{'UnbrokenToken'.repeat(200)}</span>
      }
      const { container } = fixture(<Child />, policy)
      expect(initial).toEqual(['hilos-table-text'])
      expect(container.querySelector('.hilos-table-text')?.textContent).toBe(
        'UnbrokenToken'.repeat(200),
      )
      expect(container.querySelectorAll('.hilos-stack')).toHaveLength(
        policy && policy.mode !== 'content' ? 1 : 0,
      )
    },
  )

  it.each(Array.from({ length: 12 }, (_, index) => index + 1))(
    'uses the line catalog token %i',
    (count) => {
      const { container } = fixture('', {
        mode: 'lines',
        count,
      } as HilosTableRowHeight)
      expect(container.querySelector('.hilos-table-text')?.className).toBe(
        `hilos-table-text hilos-table-text-lines-${count}`,
      )
    },
  )

  it.each([2, 3, 4.5, 6, 9, 12])('uses the rem catalog token %s', (rem) => {
    const { container } = fixture('Short', {
      mode: 'height',
      rem,
    } as HilosTableRowHeight)
    expect(container.querySelector('.hilos-table-text')?.className).toBe(
      `hilos-table-text hilos-table-text-height-${String(rem).replace('.', '-')}`,
    )
  })

  it.each([
    '',
    'Short',
    'Long '.repeat(200),
    'First\nSecond\nThird',
    'UnbrokenToken'.repeat(200),
  ])(
    'keeps the complete plain value once and reserves a data-free control twin (case %#)',
    (value) => {
      const { container } = fixture(value, { mode: 'lines', count: 2 })
      const clip = container.querySelector('.hilos-table-text')!
      expect(clip.textContent).toBe(value)
      expect(clip.closest('[aria-hidden]')).toBeNull()
      expect(clip.childNodes).toHaveLength(1)
      const twin = container.querySelector('.invisible')!
      expect(twin.textContent).toBe('Show in full')
      expect(twin.hasAttribute('inert')).toBe(true)
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(
        twin.querySelector('[id], [data-id], button, a, input, [tabindex]'),
      ).toBeNull()
      expect(container.querySelector('button')).toBeNull()
    },
  )

  it('keeps an undeclared renderer intrinsic', () => {
    const { container, props, rerender } = fixture('Initial')
    rerender(
      <HilosTableValue {...props} heightContent={undefined}>
        <button type="button">Act</button>
      </HilosTableValue>,
    )
    expect(container.innerHTML).toBe('<button type="button">Act</button>')
  })

  it('starts a newly declared text region uncapped, then returns it to intrinsic rendering', () => {
    const initial: string[] = []
    function Child() {
      const node = useRef<HTMLSpanElement>(null)
      useLayoutEffect(() => {
        initial.push(node.current!.parentElement!.parentElement!.className)
      }, [])
      return <span ref={node}>Text</span>
    }
    const { container, props, rerender } = fixture('Intrinsic', {
      mode: 'lines',
      count: 2,
    })
    rerender(
      <HilosTableValue {...props} heightContent={undefined}>
        Intrinsic
      </HilosTableValue>,
    )
    expect(container.innerHTML).toBe('Intrinsic')
    rerender(
      <HilosTableValue {...props}>
        <Child />
      </HilosTableValue>,
    )
    expect(initial).toEqual(['hilos-table-text'])
    expect(container.querySelector('.hilos-table-text-lines-2')).not.toBeNull()
    rerender(
      <HilosTableValue {...props} heightContent={undefined}>
        Intrinsic again
      </HilosTableValue>,
    )
    expect(container.innerHTML).toBe('Intrinsic again')
  })

  it('diagnoses one unsafe episode across the wrapper StrictMode replay', () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { props, rerender } = fixture('Initial')
    rerender(
      <StrictMode>
        <HilosTableValue {...props}>
          <button type="button">Act</button>
        </HilosTableValue>
      </StrictMode>,
    )
    expect(warning).toHaveBeenCalledTimes(1)
  })

  it.each([
    <a href="/next">Link</a>,
    <button type="button">Act</button>,
    <input aria-label="Input" />,
    <img alt="Image" />,
    <div>Block</div>,
    <span className="badge">Ready</span>,
    <span tabIndex={0}>Focusable</span>,
    <span role="status">Status</span>,
    <span contentEditable suppressContentEditableWarning>
      Editable
    </span>,
    <HilosHideable value={HIDDEN_VALUE} />,
  ])('refuses actual unsafe child-component DOM (%j)', (child) => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    function Child() {
      return <span>{child}</span>
    }
    const { container } = fixture(
      <StrictMode>
        <Child />
      </StrictMode>,
      { mode: 'lines', count: 2 },
    )
    expect(container.querySelector('.hilos-table-text')).toBeNull()
    expect(container.querySelector('.invisible')).toBeNull()
    expect(warning).toHaveBeenCalledTimes(1)
    expect(warning).toHaveBeenCalledWith(
      "HilosTableValue: unsafe text slot for name; use heightContent: 'parts' and HilosTableText.",
    )
  })

  it('checks child-only mutations safe → unsafe → safe and diagnoses each unsafe episode once', async () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    let change!: (unsafe: boolean) => void
    function Child() {
      const [unsafe, setUnsafe] = useState(false)
      change = setUnsafe
      return unsafe ? <button type="button">Act</button> : <em>Text</em>
    }
    const { container } = fixture(<Child />, { mode: 'lines', count: 2 })
    expect(container.querySelector('.hilos-table-text-lines-2')).not.toBeNull()
    act(() => change(true))
    await settle()
    expect(container.querySelector('.hilos-table-text')).toBeNull()
    expect(container.querySelector('button')?.textContent).toBe('Act')
    act(() => change(true))
    await settle()
    expect(warning).toHaveBeenCalledTimes(1)
    act(() => change(false))
    await settle()
    expect(container.querySelector('.hilos-table-text-lines-2')).not.toBeNull()
    act(() => change(true))
    await settle()
    expect(warning).toHaveBeenCalledTimes(2)
  })

  it('rechecks computed display when descendant and ancestor classes change', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const computed = window.getComputedStyle.bind(window)
    vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => {
      const style = computed(element)
      if (
        element.matches('[data-text]') &&
        (element.classList.contains('d-block') ||
          element.closest('.block-descendants'))
      )
        return new Proxy(style, {
          get: (target, key) =>
            key === 'display' ? 'block' : Reflect.get(target, key),
        })
      return style
    })
    const { container } = fixture(<span data-text>Text</span>, {
      mode: 'lines',
      count: 2,
    })
    const node = container.querySelector('[data-text]')!
    node.classList.add('d-block')
    await settle()
    expect(container.querySelector('.hilos-table-text')).toBeNull()
    node.classList.remove('d-block')
    await settle()
    expect(container.querySelector('.hilos-table-text-lines-2')).not.toBeNull()
    container.classList.add('block-descendants')
    await settle()
    expect(container.querySelector('.hilos-table-text')).toBeNull()
    container.classList.remove('block-descendants')
    await settle()
    expect(container.querySelector('.hilos-table-text-lines-2')).not.toBeNull()
  })

  it('releases queued DOM inspections on unmount and survives StrictMode replay', () => {
    const callbacks: MutationCallback[] = []
    const disconnect = vi.fn()
    vi.stubGlobal(
      'MutationObserver',
      class {
        constructor(callback: MutationCallback) {
          callbacks.push(callback)
        }
        observe() {}
        disconnect = disconnect
      },
    )
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { props, rerender, unmount, container } = fixture('Text', {
      mode: 'lines',
      count: 2,
    })
    rerender(
      <StrictMode>
        <HilosTableValue {...props}>Text</HilosTableValue>
      </StrictMode>,
    )
    expect(container.querySelector('.hilos-table-text-lines-2')).not.toBeNull()
    unmount()
    for (const callback of callbacks) callback([], {} as MutationObserver)
    expect(disconnect).toHaveBeenCalled()
    expect(warning).not.toHaveBeenCalled()
  })
})
