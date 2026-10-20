// The unit of the maintenance surface itself (HilosMaintenancePage.test.ts is the
// admin page of that name, a different surface): the code field and the room of
// its refusal, which HilosFormError draws. Mirrors the pass-refusal cases of
// vue/src/HilosMaintenance.test.ts and react/test/HilosMaintenance.test.tsx.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { PROTECTED_MODE_PASS_COPY } from '@hilos/core'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'

import { HilosMaintenance } from '../src/HilosMaintenance.js'

const VERIFYING: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  bannerMessage: undefined,
  acceptsPass: true,
  passIssued: false,
  passRejected: false,
}

/** The verification window once the operator minted a code. */
const MINTED: ProtectedModeStatus = { ...VERIFYING, passIssued: true }

/** The window, after a code that opened nothing. */
const REJECTED: ProtectedModeStatus = { ...MINTED, passRejected: true }

/** The connection the surface reads: it only subscribes and presents the code. */
function fakeConnection(): {
  connection: HilosConnection
  presented: string[]
} {
  const presented: string[] = []
  const connection = {
    on(): () => void {
      return () => {}
    },
    presentProtectedModePass(pass: string): void {
      presented.push(pass)
    },
  } as unknown as HilosConnection

  return { connection, presented }
}

/**
 * Mount the surface with its inputs set before the first render.
 *
 * @param status The freeze to render.
 * @param connection The connection a presented code is carried on.
 * @param adminSurface Whether the url names an administrative surface.
 * @returns The mounted fixture.
 */
function mount(
  status: ProtectedModeStatus,
  connection: HilosConnection,
  adminSurface = true,
): ComponentFixture<HilosMaintenance> {
  const fixture = TestBed.createComponent(HilosMaintenance)
  fixture.componentRef.setInput('status', status)
  fixture.componentRef.setInput('connection', connection)
  fixture.componentRef.setInput('adminSurface', adminSurface)
  fixture.detectChanges()

  return fixture
}

/**
 * Hand the surface the next freeze state and render it.
 *
 * @param fixture The mounted surface.
 * @param status The state to show.
 */
function update(
  fixture: ComponentFixture<HilosMaintenance>,
  status: ProtectedModeStatus,
): void {
  fixture.componentRef.setInput('status', status)
  fixture.detectChanges()
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/**
 * Type a code into the field the way a visitor does.
 *
 * @param fixture The mounted surface.
 * @param value The code to type.
 */
function type(
  fixture: ComponentFixture<HilosMaintenance>,
  value: string,
): void {
  const field = byId('maintenance-pass') as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
  fixture.detectChanges()
}

describe('HilosMaintenance', () => {
  // The refusal's detail panel is a HilosModal: nothing may outlive its case.
  afterEach(() => {
    document.body.classList.remove('modal-open')
  })

  it('holds the room of the refusal from the moment the field appears', () => {
    mount(MINTED, fakeConnection().connection)

    expect(byId('maintenance-pass-error-slot')).not.toBeNull()
    const idle = byId('maintenance-pass-error-idle')
    expect(idle).not.toBeNull()
    expect(idle?.getAttribute('aria-hidden')).toBe('true')
    expect(idle?.classList.contains('invisible')).toBe(true)
    expect(idle?.querySelector('button')).toBeNull()
    expect(byId('maintenance-pass-error')).toBeNull()
    expect(byId('maintenance-pass-error-details')).toBeNull()
    expect(byId('maintenance-pass')?.hasAttribute('aria-describedby')).toBe(
      false,
    )
  })

  it('draws, opens and clears the refusal in the same slot of one mount', () => {
    const { connection, presented } = fakeConnection()
    const fixture = mount(MINTED, connection)
    const rejected = PROTECTED_MODE_PASS_COPY.rejected
    const field = (): HTMLInputElement =>
      byId('maintenance-pass') as HTMLInputElement
    const details = (): HTMLElement | null =>
      byId('maintenance-pass-error-details')
    const full = (): HTMLElement | null => byId('maintenance-pass-error-full')

    type(fixture, 'typo')
    const fieldNode = field()
    const surfaceNode = byId('maintenance')
    const slotNode = byId('maintenance-pass-error-slot')

    // The refusal arrives on the same mount: nothing is mounted again.
    update(fixture, REJECTED)
    expect(field()).toBe(fieldNode)
    expect(byId('maintenance')).toBe(surfaceNode)
    expect(byId('maintenance-pass-error-slot')).toBe(slotNode)
    expect(
      document.querySelectorAll('[data-id="maintenance-pass-error"]'),
    ).toHaveLength(1)
    const row = byId('maintenance-pass-error') as HTMLElement
    expect(row.querySelector('span.text-truncate')?.textContent).toBe(rejected)
    expect(details()?.getAttribute('aria-label')).toBe('Show error details')
    expect(fieldNode.value).toBe('typo')
    expect(fieldNode.classList.contains('is-invalid')).toBe(true)
    expect(fieldNode.getAttribute('aria-invalid')).toBe('true')
    expect(fieldNode.getAttribute('aria-describedby')).toBe(
      'maintenance-pass-error',
    )
    const target = document.getElementById('maintenance-pass-error')
    expect(target).not.toBeNull()
    expect(target?.contains(row)).toBe(true)
    expect(target?.textContent).toContain(rejected)
    // The sentence is the surface's own live region's to announce, once.
    for (const node of [slotNode, row, target]) {
      expect(node?.getAttribute('role')).toBeNull()
      expect(node?.getAttribute('aria-live')).toBeNull()
    }
    expect(surfaceNode?.getAttribute('role')).toBe('status')
    expect(surfaceNode?.getAttribute('aria-live')).toBe('polite')

    // The details button opens the panel and does not present the code again.
    details()?.click()
    fixture.detectChanges()
    expect(document.querySelector('.modal-title')?.textContent).toContain(
      'Error details',
    )
    expect(full()?.textContent?.trim()).toBe(rejected)
    expect(presented).toEqual([])
    byId('maintenance-pass-error-close')?.click()
    fixture.detectChanges()
    expect(full()).toBeNull()
    details()?.click()
    fixture.detectChanges()
    expect(full()?.textContent?.trim()).toBe(rejected)

    // The refusal is lifted while the panel is open: panel and row go, the slot
    // and the field stay, the twin is back and the field points at nothing.
    update(fixture, MINTED)
    expect(full()).toBeNull()
    expect(byId('maintenance-pass-error')).toBeNull()
    expect(details()).toBeNull()
    expect(byId('maintenance-pass-error-idle')).not.toBeNull()
    expect(field()).toBe(fieldNode)
    expect(byId('maintenance-pass-error-slot')).toBe(slotNode)
    expect(fieldNode.value).toBe('typo')
    expect(fieldNode.hasAttribute('aria-describedby')).toBe(false)

    // A new refusal shows the same text and button; the old panel stays shut.
    update(fixture, REJECTED)
    expect(byId('maintenance-pass-error-slot')).toBe(slotNode)
    expect(
      byId('maintenance-pass-error')?.querySelector('span.text-truncate')
        ?.textContent,
    ).toBe(rejected)
    expect(details()).not.toBeNull()
    expect(full()).toBeNull()
    expect(fieldNode.getAttribute('aria-describedby')).toBe(
      'maintenance-pass-error',
    )
  })

  it('has no slot without the field, and submits the typed code', () => {
    const { connection, presented } = fakeConnection()

    // No code minted yet: the sentence stands in the field's place.
    const waiting = mount(VERIFYING, connection)
    expect(byId('maintenance-pass-error-slot')).toBeNull()
    expect(byId('maintenance-pass')).toBeNull()
    waiting.destroy()

    // A public url is not invited to fill in a key.
    const publicUrl = mount(MINTED, connection, false)
    expect(byId('maintenance-pass-error-slot')).toBeNull()
    publicUrl.destroy()

    const fixture = mount(MINTED, connection)
    type(fixture, 'typo')
    byId('maintenance-pass-form')?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()
    expect(presented).toEqual(['typo'])
    expect((byId('maintenance-pass') as HTMLInputElement).value).toBe('typo')
  })
})
