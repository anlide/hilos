import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { HilosSettingValueCell } from '../src/admin/settings/HilosSettingValueCell.js'

describe('HilosSettingValueCell', () => {
  afterEach(cleanup)

  it('renders a disabled, checked box for an enabled boolean', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="1"
        type="boolean"
        valueSource="override"
        defaultReferenceKey={null}
      />,
    )
    const box = container.querySelector(
      'input[type="checkbox"]',
    ) as HTMLInputElement
    expect(box).not.toBeNull()
    expect(box.checked).toBe(true)
    expect(box.disabled).toBe(true)
  })

  it('italicizes a numeric value', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="42"
        type="integer"
        valueSource="default"
        defaultReferenceKey={null}
      />,
    )
    expect(container.querySelector('.fst-italic')?.textContent).toBe('42')
  })

  it('shows an empty-string chip for a blank string', () => {
    const { container } = render(
      <HilosSettingValueCell
        value=""
        type="string"
        valueSource="orphan"
        defaultReferenceKey={null}
      />,
    )
    expect(container.textContent).toContain('empty')
    const badge = container.querySelector(
      '[data-id="hilos-setting-not-in-catalog"]',
    )!
    expect(badge.textContent).toBe('not in catalog')
    expect(badge.getAttribute('title')).toBe(
      'This key is not in the project catalog',
    )
    expect(badge.className).toBe(
      'badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle flex-shrink-0',
    )
  })

  it('shows the reference badge with the referenced key', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="x"
        type="string"
        valueSource="reference"
        defaultReferenceKey="other_key"
      />,
    )
    expect(container.textContent).toContain('other_key')
    expect(
      container.querySelector('[data-id="hilos-setting-not-in-catalog"]'),
    ).toBeNull()
  })

  it('badges a catalog default and a custom override distinctly', () => {
    const def = render(
      <HilosSettingValueCell
        value="x"
        type="string"
        valueSource="default"
        defaultReferenceKey={null}
      />,
    )
    expect(def.container.textContent).toContain('default')
    expect(
      def.container.querySelector('[data-id="hilos-setting-not-in-catalog"]'),
    ).toBeNull()
    cleanup()

    const custom = render(
      <HilosSettingValueCell
        value="x"
        type="string"
        valueSource="override"
        defaultReferenceKey={null}
      />,
    )
    expect(custom.container.textContent).toContain('custom')
    expect(
      custom.container.querySelector(
        '[data-id="hilos-setting-not-in-catalog"]',
      ),
    ).toBeNull()
  })

  it('keeps an orphan badge beside a hidden mark without rendering a value', () => {
    const { container } = render(
      <HilosSettingValueCell
        value={{ _hidden: true }}
        type="string"
        valueSource="orphan"
        defaultReferenceKey={null}
      />,
    )
    expect(container.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-setting-not-in-catalog"]')
        ?.textContent,
    ).toBe('not in catalog')
    expect(container.querySelector('input')).toBeNull()
    expect(container.innerHTML).not.toContain('_hidden')
  })

  it('renders a disabled, unchecked box for a disabled boolean', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="0"
        type="boolean"
        valueSource="override"
        defaultReferenceKey={null}
      />,
    )
    const box = container.querySelector(
      'input[type="checkbox"]',
    ) as HTMLInputElement
    expect(box).not.toBeNull()
    expect(box.checked).toBe(false)
    expect(box.disabled).toBe(true)
    expect(box.getAttribute('aria-label')).toBe('Disabled')
  })

  it('italicizes a float value', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="1.5"
        type="float"
        valueSource="default"
        defaultReferenceKey={null}
      />,
    )
    expect(container.querySelector('.fst-italic')?.textContent).toBe('1.5')
  })

  it('renders an em dash for a null value', () => {
    const { container } = render(
      <HilosSettingValueCell
        value={null}
        type="integer"
        valueSource="override"
        defaultReferenceKey={null}
      />,
    )
    expect(container.querySelector('.fst-italic')?.textContent).toBe('—')
  })

  it('renders wrapped value cells with top-aligned flex layout, no title or truncation, and text-break reference code', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="long text value that wraps"
        type="string"
        valueSource="reference"
        defaultReferenceKey="catalog.other.key"
        wrap
      />,
    )
    const root = container.querySelector('[data-id="setting-value"]')!
    expect(root).not.toBeNull()
    expect(root.classList.contains('d-flex')).toBe(true)
    expect(root.classList.contains('align-items-start')).toBe(true)
    expect(root.classList.contains('gap-2')).toBe(true)
    expect(root.classList.contains('d-inline-flex')).toBe(false)
    expect(root.classList.contains('mw-100')).toBe(false)

    const textSpan = root.querySelector('.hilos-table-text')!
    expect(textSpan).not.toBeNull()
    expect(textSpan.classList.contains('flex-grow-1')).toBe(true)
    expect(textSpan.classList.contains('text-truncate')).toBe(false)
    expect(textSpan.getAttribute('title')).toBeNull()
    expect(textSpan.textContent).toBe('long text value that wraps')

    const refBadge = root.querySelector('.badge.text-wrap')!
    expect(refBadge).not.toBeNull()
    const code = refBadge.querySelector('code.text-break')!
    expect(code).not.toBeNull()
    expect(code.classList.contains('text-truncate')).toBe(false)
    expect(code.textContent).toBe('catalog.other.key')
  })

  it('renders wrapped numeric values with italicized table text', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="100.5"
        type="float"
        valueSource="override"
        defaultReferenceKey={null}
        wrap
      />,
    )
    const textSpan = container.querySelector('.hilos-table-text')!
    expect(textSpan.classList.contains('fst-italic')).toBe(true)
    expect(textSpan.classList.contains('flex-grow-1')).toBe(true)
    expect(textSpan.getAttribute('title')).toBeNull()
    expect(textSpan.textContent).toBe('100.5')
  })

  it('renders wrapped booleans, empty strings, and hidden values with proper marks and badges', () => {
    const bool = render(
      <HilosSettingValueCell
        value="1"
        type="boolean"
        valueSource="override"
        defaultReferenceKey={null}
        wrap
      />,
    )
    expect(
      bool.container.querySelector('input[type="checkbox"]'),
    ).not.toBeNull()
    cleanup()

    const empty = render(
      <HilosSettingValueCell
        value=""
        type="string"
        valueSource="default"
        defaultReferenceKey={null}
        wrap
      />,
    )
    expect(empty.container.textContent).toContain('empty')
    cleanup()

    const hidden = render(
      <HilosSettingValueCell
        value={{ _hidden: true }}
        type="string"
        valueSource="orphan"
        defaultReferenceKey={null}
        wrap
      />,
    )
    expect(
      hidden.container.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(hidden.container.textContent).toContain('not in catalog')
  })

  it('keeps unwrapped inline layout with text-truncate and title by default', () => {
    const { container } = render(
      <HilosSettingValueCell
        value="short value"
        type="string"
        valueSource="reference"
        defaultReferenceKey="catalog.ref.key"
      />,
    )
    const root = container.querySelector('[data-id="setting-value"]')!
    expect(root.classList.contains('d-inline-flex')).toBe(true)
    expect(root.classList.contains('align-items-center')).toBe(true)
    expect(root.classList.contains('mw-100')).toBe(true)
    expect(root.classList.contains('d-flex')).toBe(false)

    const textSpan = root.querySelector('.text-truncate')!
    expect(textSpan.getAttribute('title')).toBe('short value')
    expect(root.querySelector('.hilos-table-text')).toBeNull()

    const refBadge = root.querySelector('.badge')!
    expect(refBadge.classList.contains('text-wrap')).toBe(false)
    const code = refBadge.querySelector('code')!
    expect(code.classList.contains('text-truncate')).toBe(true)
    expect(code.classList.contains('text-break')).toBe(false)
  })
})
