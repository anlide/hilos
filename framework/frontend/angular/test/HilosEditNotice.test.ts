// The Angular port of react/test/HilosEditNotice.test.tsx and
// vue/src/HilosEditNotice.test.ts, under the same case names.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { RowEditNoticeKind } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosEditNotice } from '../src/HilosEditNotice.js'

/** A host that drives every input of the row through signals. */
@Component({
  selector: 'test-edit-notice-host',
  imports: [HilosEditNotice],
  template: `
    <hilos-edit-notice [kind]="kind()" [text]="text()" dataId="e" />
  `,
})
class EditNoticeHost {
  readonly kind = signal<RowEditNoticeKind | null>(null)
  readonly text = signal('')
}

/**
 * Mount the host with the given message and render it once.
 *
 * @param kind Which message to draw, or null for none.
 * @param text The message's text.
 * @returns The mounted fixture.
 */
function mountRow(
  kind: RowEditNoticeKind | null = null,
  text = '',
): ComponentFixture<EditNoticeHost> {
  const fixture = TestBed.createComponent(EditNoticeHost)
  fixture.componentInstance.kind.set(kind)
  fixture.componentInstance.text.set(text)
  fixture.detectChanges()

  return fixture
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosEditNotice', () => {
  it('holds the room with a hidden twin while there is nothing to say', () => {
    mountRow()

    expect(byId('e')).toBeNull()
    const idle = byId('e-idle')
    expect(idle).not.toBeNull()
    expect(idle?.getAttribute('aria-hidden')).toBe('true')
    expect(idle?.classList.contains('invisible')).toBe(true)
    // Every element of the row in its tallest form — the icon, the line of text
    // and a button-shaped stand-in — or the room would be short of the truth.
    expect(idle?.querySelector('i.bi')).not.toBeNull()
    expect(idle?.querySelector('span.text-truncate')).not.toBeNull()
    expect(idle?.querySelector('span.btn i.bi-info-circle')).not.toBeNull()
    // A span and not a button: the twin holds room, it does not take focus.
    expect(idle?.querySelector('button')).toBeNull()
  })

  it('draws a conflict and a deletion as warnings and an update as a quiet note', () => {
    const tones = {
      conflict: ['alert-warning', 'bi-exclamation-triangle'],
      deleted: ['alert-warning', 'bi-exclamation-triangle'],
      updated: ['alert-secondary', 'bi-arrow-repeat'],
    } as const
    const fixture = mountRow()
    for (const [kind, [tone, icon]] of Object.entries(tones)) {
      fixture.componentInstance.kind.set(kind as keyof typeof tones)
      fixture.componentInstance.text.set('Something')
      fixture.detectChanges()

      const row = byId('e')
      expect(row).not.toBeNull()
      expect(row?.classList.contains(tone)).toBe(true)
      expect(row?.querySelector(`i.${icon}`)).not.toBeNull()
      expect(byId('e-idle')).toBeNull()
      // The row shows; the slot above it announces. A role here too would say
      // the sentence twice.
      expect(row?.getAttribute('role')).toBeNull()
    }
  })

  it('keeps one truncated line and opens the whole text from the details button', () => {
    const text =
      'Changed elsewhere to "a value long enough to run past the row"'
    const fixture = mountRow('conflict', text)

    const line = byId('e')?.querySelector('span.flex-grow-1')
    expect(line?.textContent).toBe(text)
    expect(line?.classList.contains('text-truncate')).toBe(true)
    const details = byId('e-details')
    expect(details?.getAttribute('aria-label')).toBe('Show details')
    details?.click()
    fixture.detectChanges()

    expect(byId('e-full')?.textContent?.trim()).toBe(text)
  })

  it('closes the panel when the message goes', () => {
    const fixture = mountRow('updated', 'Updated just now')
    byId('e-details')?.click()
    fixture.detectChanges()
    expect(byId('e-full')).not.toBeNull()

    fixture.componentInstance.kind.set(null)
    fixture.componentInstance.text.set('')
    fixture.detectChanges()
    expect(byId('e-full')).toBeNull()
  })

  it('makes the slot a polite status region, with or without a message', () => {
    const fixture = mountRow()
    const slot = byId('e-slot')
    // A region that stands there before it has anything to say is the only
    // kind the reader announces from (accessibility.md, "Live regions").
    expect(slot?.getAttribute('role')).toBe('status')
    expect(slot?.getAttribute('aria-live')).toBe('polite')

    fixture.componentInstance.kind.set('updated')
    fixture.componentInstance.text.set('Updated just now')
    fixture.detectChanges()
    expect(byId('e-slot')?.getAttribute('role')).toBe('status')
    expect(byId('e-slot')?.textContent).toContain('Updated just now')
  })
})
