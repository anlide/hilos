import { type CodeSendProgress } from '@hilos/core'
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosSendProgress } from '../src/HilosSendProgress.js'

const base: CodeSendProgress = {
  state: 'sent',
  channel: 'email',
  purpose: 'change_password',
  detail: null,
  ticket: 'a1b2c3d4e5f60718',
  reason: null,
  resendAt: null,
  expiresAt: null,
}

@Component({
  selector: 'test-send-progress-host',
  imports: [HilosSendProgress],
  template: `
    <hilos-send-progress
      [progress]="progress()"
      [to]="to()"
      [resendAt]="resendAt()"
      [busy]="busy()"
      dataId="send"
      (sendAgain)="sent()"
    />
  `,
})
class SendProgressHost {
  readonly progress = signal<CodeSendProgress | null>(null)
  readonly to = signal('person@example.test')
  readonly resendAt = signal<number | null>(null)
  readonly busy = signal(false)
  sends = 0

  sent(): void {
    this.sends++
  }
}

afterEach(() => {
  vi.useRealTimers()
  document.body.classList.remove('modal-open')
})

function mountLine(
  progress: CodeSendProgress | null = null,
  resendAt: number | null = null,
): ComponentFixture<SendProgressHost> {
  const fixture = TestBed.createComponent(SendProgressHost)
  fixture.componentInstance.progress.set(progress)
  fixture.componentInstance.resendAt.set(resendAt)
  fixture.detectChanges()
  return fixture
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosSendProgress', () => {
  it('reserves the line and repeat row with no live region before a send', () => {
    mountLine()
    expect(byId('send-idle')?.getAttribute('aria-hidden')).toBe('true')
    expect(byId('send-line')).toBeNull()
    expect(byId('send-again')).toBeNull()
    expect(byId('send')?.getAttribute('aria-live')).toBeNull()
  })

  it.each([
    ['queued', 'Queued for sending'],
    ['sending', 'Sending to person@example.test'],
    ['sent', 'Sent to person@example.test'],
    ['failed', 'Could not send'],
    ['not_sent', 'Not really sent to person@example.test'],
    ['held', 'already used'],
  ])('draws %s with shared copy', (state, copy) => {
    mountLine({ ...base, state })
    expect(byId('send-line')?.textContent).toContain(copy)
  })

  it('names a live earlier code on the cooldown', () => {
    mountLine({ ...base, reason: 'code_rate_limited' })
    expect(byId('send-line')?.textContent).toContain('that code still works')
  })

  it('opens the full refusal behind details', () => {
    const fixture = mountLine({
      ...base,
      state: 'failed',
      detail: 'mailbox unavailable',
    })
    byId('send-details')?.click()
    fixture.detectChanges()
    expect(byId('send-full')?.textContent).toContain('mailbox unavailable')
  })

  it('opens the repeat button when the countdown expires', () => {
    vi.useFakeTimers()
    vi.setSystemTime(1_900_000_000_000)
    const fixture = mountLine(base, Date.now() + 42_000)
    expect(byId('send-again-in')?.textContent?.trim()).toBe(
      'Send again in 0:42',
    )
    vi.advanceTimersByTime(42_000)
    fixture.detectChanges()
    expect(byId('send-again-in')).toBeNull()
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(false)
    byId('send-again')?.click()
    fixture.detectChanges()
    expect(fixture.componentInstance.sends).toBe(1)
  })

  it('locks repeat while the transport or action is busy', () => {
    const fixture = mountLine({ ...base, state: 'queued' })
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(true)
    fixture.componentInstance.progress.set(base)
    fixture.componentInstance.busy.set(true)
    fixture.detectChanges()
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(true)
  })
})
