// The wrapper of a value a viewer of the admin view mode may be sent hidden, as
// the Vue and React kits test theirs (vue/src/HilosHideable.test.ts): the value
// as text without a template, the value handed to the projected template, and
// the mark for a hidden value with the template never stamped.
import { Component } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { HIDDEN_VALUE, type Hideable } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosHideable } from '../src/HilosHideable.js'

/** The wrapper with no template of its own. */
@Component({
  selector: 'test-hideable-text',
  imports: [HilosHideable],
  template: `<hilos-hideable [value]="value" />`,
})
class TextHost {
  value: Hideable<string | null> = 'Olena'
}

/** The wrapper with a template that counts each time it is stamped. */
@Component({
  selector: 'test-hideable-template',
  imports: [HilosHideable],
  template: `
    <hilos-hideable [value]="value">
      <ng-template let-email>
        <code data-id="shown">{{ shout(email) }}</code>
      </ng-template>
    </hilos-hideable>
  `,
})
class TemplateHost {
  value: Hideable<string> = 'olena@example.com'
  stamped = 0

  shout(email: string): string {
    this.stamped++

    return email.toUpperCase()
  }
}

/**
 * Mount a host around the wrapper with one value.
 *
 * @param host The host component.
 * @param value The value it hands the wrapper.
 */
function mount<H extends { value: unknown }>(
  host: new () => H,
  value: H['value'],
) {
  const fixture = TestBed.createComponent(host)
  fixture.componentInstance.value = value
  fixture.detectChanges()

  return fixture
}

describe('HilosHideable', () => {
  it('prints a value that is not hidden as text when given no template', () => {
    const root = mount(TextHost, 'Olena').nativeElement as HTMLElement

    expect(root.querySelector('hilos-hideable')?.textContent).toBe('Olena')
    expect(root.querySelector('[data-id="hilos-hidden"]')).toBeNull()
  })

  it('prints nothing, not the word "null", for an absent value given no template', () => {
    const root = mount(TextHost, null).nativeElement as HTMLElement

    expect(root.querySelector('hilos-hideable')?.textContent).toBe('')
  })

  it('hands a value that is not hidden to the template', () => {
    const root = mount(TemplateHost, 'olena@example.com')
      .nativeElement as HTMLElement

    expect(root.querySelector('[data-id="shown"]')?.textContent).toBe(
      'OLENA@EXAMPLE.COM',
    )
  })

  it('draws the hidden mark for a hidden value and never stamps the template', () => {
    const fixture = mount(TemplateHost, HIDDEN_VALUE)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelector('[data-id="hilos-hidden"]')?.textContent).toBe(
      'Hidden',
    )
    expect(root.querySelector('[data-id="shown"]')).toBeNull()
    expect(fixture.componentInstance.stamped).toBe(0)
  })
})
