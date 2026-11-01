import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import type { SettingValueSource } from '@hilos/core/admin/settings'
import type { Hideable } from '@hilos/core/base'

import { HilosSettingValueCell } from '../src/admin/settings/HilosSettingValueCell.js'

function mount(inputs: {
  value: Hideable<string | null>
  type: string
  valueSource: SettingValueSource
  defaultReferenceKey: Hideable<string | null>
  wrap?: boolean
}): ComponentFixture<HilosSettingValueCell> {
  const fixture = TestBed.createComponent(HilosSettingValueCell)
  fixture.componentRef.setInput('value', inputs.value)
  fixture.componentRef.setInput('type', inputs.type)
  fixture.componentRef.setInput('valueSource', inputs.valueSource)
  fixture.componentRef.setInput(
    'defaultReferenceKey',
    inputs.defaultReferenceKey,
  )
  if (inputs.wrap !== undefined) {
    fixture.componentRef.setInput('wrap', inputs.wrap)
  }
  fixture.detectChanges()

  return fixture
}

describe('HilosSettingValueCell', () => {
  it('renders wrapped value cells with top-aligned flex layout, no title or truncation, and text-break reference code', () => {
    const fixture = mount({
      value: 'long text value that wraps',
      type: 'string',
      valueSource: 'reference',
      defaultReferenceKey: 'catalog.other.key',
      wrap: true,
    })
    const root = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="setting-value"]',
    )!
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
    expect(textSpan.textContent?.trim()).toBe('long text value that wraps')

    const refBadge = root.querySelector('.badge.text-wrap')!
    expect(refBadge).not.toBeNull()
    const code = refBadge.querySelector('code.text-break')!
    expect(code).not.toBeNull()
    expect(code.classList.contains('text-truncate')).toBe(false)
    expect(code.textContent?.trim()).toBe('catalog.other.key')
  })

  it('renders wrapped numeric values with italicized table text', () => {
    const fixture = mount({
      value: '100.5',
      type: 'float',
      valueSource: 'override',
      defaultReferenceKey: null,
      wrap: true,
    })
    const root = fixture.nativeElement as HTMLElement
    const textSpan = root.querySelector('.hilos-table-text')!
    expect(textSpan.classList.contains('fst-italic')).toBe(true)
    expect(textSpan.classList.contains('flex-grow-1')).toBe(true)
    expect(textSpan.getAttribute('title')).toBeNull()
    expect(textSpan.textContent?.trim()).toBe('100.5')
  })

  it('renders wrapped booleans, empty strings, and hidden values with proper marks and badges', () => {
    const bool = mount({
      value: '1',
      type: 'boolean',
      valueSource: 'override',
      defaultReferenceKey: null,
      wrap: true,
    })
    expect(
      (bool.nativeElement as HTMLElement).querySelector(
        'input[type="checkbox"]',
      ),
    ).not.toBeNull()

    const empty = mount({
      value: '',
      type: 'string',
      valueSource: 'default',
      defaultReferenceKey: null,
      wrap: true,
    })
    expect((empty.nativeElement as HTMLElement).textContent).toContain('empty')

    const hidden = mount({
      value: { _hidden: true },
      type: 'string',
      valueSource: 'orphan',
      defaultReferenceKey: null,
      wrap: true,
    })
    expect(
      (hidden.nativeElement as HTMLElement).querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect((hidden.nativeElement as HTMLElement).textContent).toContain(
      'not in catalog',
    )
  })

  it('keeps unwrapped inline layout with text-truncate and title by default', () => {
    const fixture = mount({
      value: 'short value',
      type: 'string',
      valueSource: 'reference',
      defaultReferenceKey: 'catalog.ref.key',
    })
    const root = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="setting-value"]',
    )!
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
