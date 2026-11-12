// The union of send-line and repeat-control cases, run through real SDK views.
import {
  AUTH_CODE_REASON_RATE_LIMITED,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENT,
  PASSWORD_CHANGE_OPERATION,
  type CodeSendProgress,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The registering SDK's Vitest, including its fake clock. */
type SendProgressTestRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

/** Inputs of one real send-progress component. */
export interface SendProgressInput {
  progress: CodeSendProgress | null
  to: string
  resendAt: number | null
  busy: boolean
  dataId: string
  onSendAgain: () => void
}

/** Local lifecycle operations; clock changes are supplied by the scenario. */
export interface SendProgressView {
  readonly root: ParentNode
  update(patch: Partial<SendProgressInput>): Promise<void>
  click(target: HTMLElement): Promise<void>
  flush(change?: () => void): Promise<void>
  unmount(): Promise<void>
}

/** Mount an SDK's own component and connect its repeat event. */
type MountSendProgress = (input: SendProgressInput) => Promise<SendProgressView>

const base: CodeSendProgress = {
  state: CODE_SEND_STATE_SENT,
  channel: 'email',
  purpose: PASSWORD_CHANGE_OPERATION,
  detail: null,
  ticket: 'a1b2c3d4e5f60718',
  reason: null,
  message: null,
  resendAt: null,
  expiresAt: null,
}

/** Fresh inputs; each case states the changes it exercises. */
function inputs(patch: Partial<SendProgressInput> = {}): SendProgressInput {
  return {
    progress: null,
    to: 'person@example.test',
    resendAt: null,
    busy: false,
    dataId: 'send',
    onSendAgain: () => {},
    ...patch,
  }
}

/** Find a scenario-owned data-id, including in the details modal. */
function byId(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** Always unmount before the caller restores its fake timers. */
async function withView(
  runner: SendProgressTestRunner,
  mount: MountSendProgress,
  input: SendProgressInput,
  steps: (view: SendProgressView) => Promise<void>,
): Promise<void> {
  const view = await mount(input)
  try {
    await view.flush()
    await steps(view)
  } finally {
    await view.unmount()
  }
  runner.expect(view.root.childElementCount).toBe(0)
  runner.expect(document.body.classList.contains('modal-open')).toBe(false)
}

/** Register the send copy, details, fake-clock countdown and repeat behavior. */
export function registerSendProgressCases(
  sdk: string,
  runner: SendProgressTestRunner,
  mount: MountSendProgress,
): void {
  const { describe, it, expect, vi } = runner

  describe(`HilosSendProgress on ${sdk}`, () => {
    it('keeps an idle twin without a live announcement before a send', async () => {
      await withView(runner, mount, inputs(), async ({ root }) => {
        expect(byId(root, 'send-idle')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(byId(root, 'send-line')).toBeNull()
        expect(byId(root, 'send-again')).toBeNull()
        expect(byId(root, 'send')).not.toBeNull()
        expect(byId(root, 'send')?.getAttribute('aria-live')).toBeNull()
        expect(
          root.querySelector('[role="status"], [role="alert"], [aria-live]'),
        ).toBeNull()
      })
    })

    it.each([
      ['queued', 'Queued for sending'],
      ['sending', 'Sending to person@example.test'],
      ['sent', 'Sent to person@example.test'],
      ['failed', 'Could not send'],
      ['not_sent', 'Not really sent to person@example.test'],
      ['held', 'already used'],
    ])('draws %s with shared copy', async (state, copy) => {
      await withView(
        runner,
        mount,
        inputs({ progress: { ...base, state } }),
        async ({ root }) => {
          expect(byId(root, 'send-line')?.textContent).toContain(copy)
        },
      )
    })

    it('names the earlier code that still works on cooldown', async () => {
      await withView(
        runner,
        mount,
        inputs({
          progress: { ...base, reason: AUTH_CODE_REASON_RATE_LIMITED },
        }),
        async ({ root }) => {
          expect(byId(root, 'send-line')?.textContent).toContain(
            'that code still works',
          )
        },
      )
    })

    it('opens the complete refusal behind send details', async () => {
      await withView(
        runner,
        mount,
        inputs({
          progress: {
            ...base,
            state: CODE_SEND_STATE_FAILED,
            detail: 'mailbox unavailable',
          },
        }),
        async (view) => {
          const details = byId(view.root, 'send-details') as HTMLButtonElement
          expect(details.tagName).toBe('BUTTON')
          expect(details.getAttribute('aria-label')).toBe('Show send details')
          await view.click(details)
          await view.flush()
          expect(byId(view.root, 'send-full')?.textContent).toContain(
            'mailbox unavailable',
          )
        },
      )
    })

    it('enables repeat when its countdown expires and sends one event', async () => {
      vi.useFakeTimers()
      try {
        vi.setSystemTime(1_900_000_000_000)
        const onSendAgain = vi.fn()
        await withView(
          runner,
          mount,
          inputs({
            progress: base,
            resendAt: Date.now() + 42_000,
            onSendAgain,
          }),
          async (view) => {
            expect(byId(view.root, 'send-again-in')?.textContent?.trim()).toBe(
              'Send again in 0:42',
            )
            await view.flush(() => vi.advanceTimersByTime(42_000))
            expect(byId(view.root, 'send-again-in')).toBeNull()
            const again = byId(view.root, 'send-again') as HTMLButtonElement
            expect(again.disabled).toBe(false)
            await view.click(again)
            await view.flush()
            expect(onSendAgain).toHaveBeenCalledOnce()
          },
        )
      } finally {
        vi.useRealTimers()
      }
    })

    it('enables repeat immediately when its moment moves into the past without a tick', async () => {
      vi.useFakeTimers()
      try {
        vi.setSystemTime(1_900_000_000_000)
        await withView(
          runner,
          mount,
          inputs({ progress: base, resendAt: Date.now() + 42_000 }),
          async (view) => {
            expect(byId(view.root, 'send-again-in')?.textContent?.trim()).toBe(
              'Send again in 0:42',
            )
            vi.setSystemTime(Date.now() + 30_000)
            await view.update({ resendAt: Date.now() - 1_000 })
            await view.flush()
            expect(byId(view.root, 'send-again-in')).toBeNull()
            expect(
              (byId(view.root, 'send-again') as HTMLButtonElement).disabled,
            ).toBe(false)
          },
        )
      } finally {
        vi.useRealTimers()
      }
    })

    it('locks repeat while the transport or action is busy', async () => {
      await withView(
        runner,
        mount,
        inputs({ progress: { ...base, state: CODE_SEND_STATE_QUEUED } }),
        async (view) => {
          expect(
            (byId(view.root, 'send-again') as HTMLButtonElement).disabled,
          ).toBe(true)
          await view.update({ progress: base, busy: true })
          await view.flush()
          expect(
            (byId(view.root, 'send-again') as HTMLButtonElement).disabled,
          ).toBe(true)
        },
      )
    })
  })
}
