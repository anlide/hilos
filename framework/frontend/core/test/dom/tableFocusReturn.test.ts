// @vitest-environment jsdom
// Disabled fieldset inheritance must be exercised by the real :disabled match.

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  available,
  FocusTrap,
  registerFocusReturnFallback,
} from '../../src/dom/focusTrap.js'
import {
  captureTableFocusReturnTarget,
  type TableFocusReturnRow,
} from '../../src/dom/tableFocusReturn.js'

afterEach(() => {
  document.body.innerHTML = ''
})

/** A mounted table with its live rows and its ordinary search fallback. */
function mountTable() {
  const root = document.createElement('section')
  root.tabIndex = -1
  const search = document.createElement('input')
  root.append(search)
  document.body.append(root)
  const state = { rows: [] as TableFocusReturnRow[] }
  const capture = vi.fn((opener: HTMLElement) =>
    captureTableFocusReturnTarget(opener, () => state.rows),
  )
  const dispose = registerFocusReturnFallback(
    root,
    () => (available(search) ? search : root),
    capture,
  )

  return { root, search, state, capture, dispose }
}

/** A live record; occurrence belongs to geometry, not its return address. */
function mountRow(
  root: HTMLElement,
  rowKey = 'row-1',
  projection: TableFocusReturnRow['projection'] = 'wide',
  markup = '<button data-id="open" data-hilos-focus-return="change-log-open">Open</button>',
) {
  const part = document.createElement('div')
  part.innerHTML = markup
  root.append(part)

  return {
    rowKey,
    projection,
    parts: [part],
    part,
    control: part.querySelector('button')!,
  }
}

/** Open a real trap from the row control and keep its focus inside the dialog. */
function openFrom(opener: HTMLElement) {
  const dialog = document.createElement('div')
  dialog.tabIndex = -1
  document.body.append(dialog)
  const trap = new FocusTrap()
  opener.focus()
  expect(document.activeElement).toBe(opener)
  trap.activate(dialog)
  expect(document.activeElement).toBe(dialog)

  return { trap, dialog }
}

describe('captureTableFocusReturnTarget', () => {
  it('captures the live opener at activate and returns to a new occurrence before search', () => {
    const table = mountTable()
    const original = mountRow(table.root)
    original.part.dataset.occurrence = '1'
    table.state.rows = [original]
    const { trap, dialog } = openFrom(original.control)
    expect(table.capture).toHaveBeenCalledExactlyOnceWith(original.control)
    expect(table.capture.mock.results[0].value).toBeTypeOf('function')
    original.part.remove()
    const replacement = mountRow(table.root)
    replacement.part.dataset.occurrence = '5'
    table.state.rows = [replacement]
    expect(original.control.isConnected).toBe(false)
    expect(document.activeElement).toBe(dialog)
    trap.release()
    expect(document.activeElement).toBe(replacement.control)
    table.dispose()
  })

  it.each([
    '<button>Open</button>',
    '<button data-id="open">Open</button>',
    '<button data-hilos-focus-return="">Open</button>',
    '<div data-hilos-focus-return="change-log-open"><button>Open</button></div>',
  ])('does not invent an opt-in for %s', (markup) => {
    const table = mountTable()
    const original = mountRow(table.root, 'row-1', 'wide', markup)
    table.state.rows = [original]
    const { trap } = openFrom(original.control)
    expect(table.capture.mock.results[0].value).toBeNull()
    original.part.remove()
    table.state.rows = [mountRow(table.root)]
    trap.release()
    expect(document.activeElement).toBe(table.search)
  })

  it.each(['missing', 'ambiguous'] as const)(
    'refuses a %s containing record at activation',
    (state) => {
      const table = mountTable()
      const original = mountRow(table.root)
      table.state.rows =
        state === 'missing'
          ? []
          : [original, { ...original, rowKey: 'another-row' }]
      const { trap } = openFrom(original.control)
      expect(table.capture.mock.results[0].value).toBeNull()
      original.part.remove()
      table.state.rows = [mountRow(table.root)]
      trap.release()
      expect(document.activeElement).toBe(table.search)
    },
  )

  it.each(['row', 'projection', 'control'] as const)(
    'does not return to a different %s address',
    (changed) => {
      const table = mountTable()
      const original = mountRow(table.root)
      table.state.rows = [original]
      const { trap } = openFrom(original.control)
      original.part.remove()
      const replacement = mountRow(
        table.root,
        changed === 'row' ? 'row-2' : 'row-1',
        changed === 'projection' ? 'card' : 'wide',
      )
      if (changed === 'control') {
        replacement.control.setAttribute('data-hilos-focus-return', 'other')
      }
      table.state.rows = [replacement]
      trap.release()
      expect(document.activeElement).toBe(table.search)
    },
  )

  it('does not search another table or a node moved outside the current record parts', () => {
    const table = mountTable()
    const other = mountTable()
    const original = mountRow(table.root)
    table.state.rows = [original]
    const { trap } = openFrom(original.control)
    original.part.remove()
    const replacement = mountRow(table.root)
    table.state.rows = [replacement]
    other.root.append(replacement.control)
    other.state.rows = [mountRow(other.root)]
    trap.release()
    expect(document.activeElement).toBe(table.search)
  })

  it.each(['empty', 'placeholder', 'detached'] as const)(
    'uses search with an %s current row reader',
    (state) => {
      const table = mountTable()
      const original = mountRow(table.root)
      table.state.rows = [original]
      const { trap } = openFrom(original.control)
      original.part.remove()
      if (state === 'empty') table.state.rows = []
      if (state === 'placeholder') {
        const placeholder = document.createElement('div')
        table.root.append(placeholder)
        table.state.rows = [{ ...original, parts: [placeholder] }]
      }
      trap.release()
      expect(document.activeElement).toBe(table.search)
    },
  )

  it.each(['same-part', 'other-part'] as const)(
    'rejects two available controls with the same literal key in %s',
    (location) => {
      const table = mountTable()
      const original = mountRow(table.root)
      table.state.rows = [original]
      const { trap } = openFrom(original.control)
      original.part.remove()
      const replacement = mountRow(table.root)
      if (location === 'same-part') {
        replacement.part.append(replacement.control.cloneNode(true))
        table.state.rows = [replacement]
      } else {
        const detail = mountRow(table.root)
        table.state.rows = [
          { ...replacement, parts: [replacement.part, detail.part] },
        ]
      }
      trap.release()
      expect(document.activeElement).toBe(table.search)
    },
  )

  it('keeps the addresses of two dialogs from the same table independent', () => {
    const table = mountTable()
    const firstRow = mountRow(table.root)
    const secondRow = mountRow(table.root, 'row-2')
    table.state.rows = [firstRow, secondRow]
    const first = openFrom(firstRow.control)
    const second = openFrom(secondRow.control)
    firstRow.part.remove()
    secondRow.part.remove()
    const firstReplacement = mountRow(table.root)
    const secondReplacement = mountRow(table.root, 'row-2')
    table.state.rows = [firstReplacement, secondReplacement]
    second.trap.release()
    expect(document.activeElement).toBe(secondReplacement.control)
    first.dialog.focus()
    first.trap.release()
    expect(document.activeElement).toBe(firstReplacement.control)
  })

  it('deduplicates overlapping card main, detail and work parts and ignores a hidden wide projection', () => {
    const table = mountTable()
    const original = mountRow(table.root, 'row-1', 'card')
    table.state.rows = [original]
    const { trap } = openFrom(original.control)
    original.part.remove()
    const card = mountRow(table.root, 'row-1', 'card')
    const work = document.createElement('div')
    card.part.append(work)
    work.append(card.control)
    table.state.rows = [
      { ...card, parts: [card.part, work, card.part] },
      mountRow(table.root, 'row-1', 'wide'),
    ]
    table.state.rows[1].parts[0].hidden = true
    trap.release()
    expect(document.activeElement).toBe(card.control)
  })

  it('compares arbitrary marker values literally instead of interpolating a selector', () => {
    const table = mountTable()
    const original = mountRow(table.root)
    const key = 'check"] [data-id="other\\value'
    original.control.setAttribute('data-hilos-focus-return', key)
    table.state.rows = [original]
    const { trap } = openFrom(original.control)
    original.part.remove()
    const replacement = mountRow(table.root)
    replacement.control.setAttribute('data-hilos-focus-return', key)
    table.state.rows = [replacement]
    trap.release()
    expect(document.activeElement).toBe(replacement.control)
  })

  it('includes a marked record part itself as a target', () => {
    const table = mountTable()
    const original = mountRow(table.root)
    table.state.rows = [{ ...original, parts: [original.control] }]
    const { trap } = openFrom(original.control)
    original.part.remove()
    const replacement = mountRow(table.root)
    table.state.rows = [{ ...replacement, parts: [replacement.control] }]
    trap.release()
    expect(document.activeElement).toBe(replacement.control)
  })

  it.each([
    'hidden-control',
    'hidden-part',
    'inert-control',
    'inert-part',
    'disabled',
    'fieldset',
    'display-none',
    'visibility-hidden',
    'visibility-collapse',
    'detached',
  ] as const)('rejects an unavailable replacement: %s', (state) => {
    const table = mountTable()
    const original = mountRow(table.root)
    table.state.rows = [original]
    const { trap } = openFrom(original.control)
    original.part.remove()
    const replacement = mountRow(table.root)
    table.state.rows = [replacement]
    if (state === 'hidden-control') replacement.control.hidden = true
    if (state === 'hidden-part') replacement.part.hidden = true
    if (state === 'inert-control') replacement.control.setAttribute('inert', '')
    if (state === 'inert-part') replacement.part.setAttribute('inert', '')
    if (state === 'disabled') replacement.control.disabled = true
    if (state === 'fieldset') {
      const fieldset = document.createElement('fieldset')
      fieldset.disabled = true
      replacement.part.append(fieldset)
      fieldset.append(replacement.control)
    }
    // The test document has no application CSS; exercise actual computed styles.
    const sheet = document.createElement('style')
    sheet.textContent = `
      .d-none { display: none !important; }
      .invisible { visibility: hidden !important; }
      .test-collapse { visibility: collapse; }
    `
    document.body.append(sheet)
    if (state === 'display-none') replacement.part.classList.add('d-none')
    if (state === 'visibility-hidden') {
      replacement.part.classList.add('invisible')
    }
    if (state === 'visibility-collapse') {
      replacement.control.classList.add('test-collapse')
    }
    if (state === 'detached') replacement.part.remove()
    trap.release()
    expect(document.activeElement).toBe(table.search)
  })

  it('invalidates a captured logical reader when the table is disposed', () => {
    const table = mountTable()
    const original = mountRow(table.root)
    table.state.rows = [original]
    const { trap, dialog } = openFrom(original.control)
    original.part.remove()
    table.state.rows = [mountRow(table.root)]
    table.dispose()
    trap.release()
    expect(document.activeElement).toBe(dialog)
  })

  it('does not use a still-connected target after the table root is unmounted', () => {
    const table = mountTable()
    const original = mountRow(table.root)
    table.state.rows = [original]
    const { trap, dialog } = openFrom(original.control)
    original.part.remove()
    const replacement = mountRow(table.root)
    table.state.rows = [replacement]
    document.body.append(replacement.part)
    table.root.remove()
    trap.release()
    expect(document.activeElement).toBe(dialog)
  })
})

describe('tile table logical focus return', () => {
  it('returns to the marked tile replacement while wide and card contain the same address', () => {
    const table = mountTable()
    const original = mountRow(table.root, 'row-1', 'tiles')
    table.state.rows = [original]
    const { trap, dialog } = openFrom(original.control)
    original.part.remove()
    const wide = mountRow(table.root, 'row-1', 'wide')
    const card = mountRow(table.root, 'row-1', 'card')
    const tile = mountRow(table.root, 'row-1', 'tiles')
    wide.part.hidden = true
    card.part.setAttribute('inert', '')
    table.state.rows = [wide, card, tile]
    expect(document.activeElement).toBe(dialog)
    trap.release()
    expect(document.activeElement).toBe(tile.control)
  })

  it.each(['wide', 'card'] as const)(
    'uses search when only a %s replacement has the tile marker',
    (projection) => {
      const table = mountTable()
      const original = mountRow(table.root, 'row-1', 'tiles')
      table.state.rows = [original]
      const { trap } = openFrom(original.control)
      original.part.remove()
      table.state.rows = [mountRow(table.root, 'row-1', projection)]
      trap.release()
      expect(document.activeElement).toBe(table.search)
    },
  )

  it.each([
    'missing',
    'ambiguous',
    'hidden',
    'inert',
    'disabled',
    'detached',
  ] as const)(
    'uses search for an unavailable or ambiguous tile: %s',
    (state) => {
      const table = mountTable()
      const original = mountRow(table.root, 'row-1', 'tiles')
      table.state.rows = [original]
      const { trap } = openFrom(original.control)
      original.part.remove()
      const replacement = mountRow(table.root, 'row-1', 'tiles')
      table.state.rows = [
        mountRow(table.root, 'row-1', 'wide'),
        mountRow(table.root, 'row-1', 'card'),
        ...(state === 'missing' ? [] : [replacement]),
      ]
      if (state === 'ambiguous') {
        replacement.part.append(replacement.control.cloneNode(true))
      }
      if (state === 'hidden') replacement.part.hidden = true
      if (state === 'inert') replacement.part.setAttribute('inert', '')
      if (state === 'disabled') replacement.control.disabled = true
      if (state === 'detached') replacement.part.remove()
      trap.release()
      expect(document.activeElement).toBe(table.search)
    },
  )

  it('uses the stable table root when the tile departed and search is hidden', () => {
    const table = mountTable()
    const original = mountRow(table.root, 'row-1', 'tiles')
    table.state.rows = [original]
    const { trap } = openFrom(original.control)
    original.part.remove()
    table.state.rows = [
      mountRow(table.root, 'row-1', 'wide'),
      mountRow(table.root, 'row-1', 'card'),
    ]
    table.search.hidden = true
    trap.release()
    expect(document.activeElement).toBe(table.root)
  })
})
