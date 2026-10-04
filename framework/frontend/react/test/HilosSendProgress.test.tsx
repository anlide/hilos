import { type CodeSendProgress } from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function draw(
  progress: CodeSendProgress | null = null,
  resendAt: number | null = null,
) {
  return render(
    <HilosSendProgress
      progress={progress}
      resendAt={resendAt}
      to="person@example.test"
      busy={false}
      dataId="send"
      onSendAgain={() => undefined}
    />,
  )
}

describe('HilosSendProgress', () => {
  it('reserves the line and repeat row with no live region before a send', () => {
    draw()
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
    draw({ ...base, state })
    expect(byId('send-line')?.textContent).toContain(copy)
  })

  it('names a live earlier code on the cooldown', () => {
    draw({ ...base, reason: 'code_rate_limited' })
    expect(byId('send-line')?.textContent).toContain('that code still works')
  })

  it('opens the whole refusal behind details', () => {
    draw({ ...base, state: 'failed', detail: 'mailbox unavailable' })
    fireEvent.click(byId('send-details') as HTMLElement)
    expect(byId('send-full')?.textContent).toContain('mailbox unavailable')
  })

  it('opens the repeat button when the countdown expires', () => {
    vi.useFakeTimers()
    vi.setSystemTime(1_900_000_000_000)
    const onSendAgain = vi.fn()
    render(
      <HilosSendProgress
        progress={base}
        resendAt={Date.now() + 42_000}
        to="person@example.test"
        busy={false}
        dataId="send"
        onSendAgain={onSendAgain}
      />,
    )
    expect(byId('send-again-in')?.textContent?.trim()).toBe(
      'Send again in 0:42',
    )
    act(() => vi.advanceTimersByTime(42_000))
    expect(byId('send-again-in')).toBeNull()
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(false)
    fireEvent.click(byId('send-again') as HTMLElement)
    expect(onSendAgain).toHaveBeenCalledOnce()
  })

  it('opens the repeat button at once when the moment moves into the past', () => {
    vi.useFakeTimers()
    vi.setSystemTime(1_900_000_000_000)
    const view = render(
      <HilosSendProgress
        progress={base}
        resendAt={Date.now() + 42_000}
        to="person@example.test"
        busy={false}
        dataId="send"
        onSendAgain={() => undefined}
      />,
    )
    expect(byId('send-again-in')?.textContent?.trim()).toBe(
      'Send again in 0:42',
    )
    // The clock moves on without a tick, and the pause ends: the moment is now.
    vi.setSystemTime(Date.now() + 30_000)
    view.rerender(
      <HilosSendProgress
        progress={base}
        resendAt={Date.now() - 1_000}
        to="person@example.test"
        busy={false}
        dataId="send"
        onSendAgain={() => undefined}
      />,
    )
    expect(byId('send-again-in')).toBeNull()
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(false)
  })

  it('locks repeat while the transport or action is busy', () => {
    const view = draw({ ...base, state: 'queued' })
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(true)
    view.rerender(
      <HilosSendProgress
        progress={base}
        resendAt={null}
        to="person@example.test"
        busy={true}
        dataId="send"
        onSendAgain={() => undefined}
      />,
    )
    expect((byId('send-again') as HTMLButtonElement).disabled).toBe(true)
  })
})
