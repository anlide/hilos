import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  ScopeManager,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { expect, it, vi } from 'vitest'
import { HilosProfileSignInPage } from '../src/profile/HilosProfileSignInPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

it('adds a phone through two steps and releases its listeners', async () => {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn(() => ({
    loading: createSignal(false),
    done: Promise.resolve({}),
  }))
  const context = {
    actions: { dispatch },
    connection: {
      on: (_: string, handler: (signal: ProjectSignal) => void) => {
        listeners.add(handler)
        return () => listeners.delete(handler)
      },
    },
    scopes: new ScopeManager(),
    channels: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
  } as unknown as HilosAuthContext
  const router = {
    pageIdentity: createSignal(undefined),
    currentRoute: createSignal({
      page: 'hilos_profile_sign_in',
      params: {},
      admin: false,
    }),
  } as unknown as HilosRouter
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosProfileSignInPage)
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput(
    'methods',
    resolveHilosProfileSignInMethods([], []),
  )
  fixture.detectChanges()
  const node = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
      `[data-id="${id}"]`,
    )!
  node('profile-sign-in-add').click()
  fixture.detectChanges()
  node('profile-sign-in-choose-phone').click()
  fixture.detectChanges()
  const fill = (id: string, value: string) => {
    const input = node(id) as HTMLInputElement
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
  }
  fill('profile-add-sms-phone', '+15551234567')
  node('profile-add-sms-request').click()
  await fixture.whenStable()
  fixture.detectChanges()
  fill('profile-add-sms-code', '123456')
  node('profile-add-sms-confirm').click()
  await fixture.whenStable()
  fixture.detectChanges()
  expect(dispatch).toHaveBeenLastCalledWith('profile_add_sms_confirm', {
    phone: '+15551234567',
    code: '123456',
  })
  expect(node('profile-sign-in-add-modal')).toBeNull()
  fixture.destroy()
  expect(listeners.size).toBe(0)
})
