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
})
