import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosChannelFrame,
  createHilosChannelFrameEdit,
  createHilosCommunicationsActions,
  type HilosLetterFrame,
} from '../../../src/admin/communications/index.js'
import {
  ActionError,
  ActionLifecycle,
  type ActionHandle,
  type ActionLifecycleSource,
} from '../../../src/connection/actionLifecycle.js'
import { Emitter } from '../../../src/connection/events.js'
import {
  HilosConnection,
  type ConnectionState,
} from '../../../src/connection/HilosConnection.js'
import {
  type ActionErrorSignal,
  type ActionSuccessSignal,
} from '../../../src/protocol/parseSignal.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const html = '<html lang="[[:lang]]"><body>[[:content]]</body></html>'
const mine = '<main>[[:content]]</main>'
const theirs = '<article>[[:content]]</article>'

function frame(patch: Partial<HilosLetterFrame> = {}): HilosLetterFrame {
  return {
    channel: 'email',
    html,
    source: 'settings',
    previewHtml: '<html lang="en"><body>482913</body></html>',
    ...patch,
  }
}

/** Real page-data model and channel actions, with replies controlled by the test. */
function harness(initial: HilosLetterFrame | undefined = frame()) {
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
  page.data.set('letterFrame', initial)
  const source = createHilosChannelFrame({ scopes })
  const replies = new Emitter<{
    actionSuccess: ActionSuccessSignal
    actionError: ActionErrorSignal
    state: ConnectionState
  }>()
  const sendAction = vi.fn<ActionLifecycleSource['sendAction']>(() => true)
  const actions = createHilosCommunicationsActions({
    connection: new HilosConnection({ url: 'ws://test/ws' }),
    scopes,
    actions: new ActionLifecycle({
      sendAction,
      on: (event, listener) => replies.on(event, listener),
    }),
  })
  const edit = createHilosChannelFrameEdit(source, actions)
  edit.start()
  const refusals: unknown[] = []
  const run = vi.fn(async (handle: ActionHandle) => {
    try {
      await handle.done
      return true
    } catch (error) {
      refusals.push(error)
      return false
    }
  })
  function succeed(): void {
    replies.emit('actionSuccess', {
      kind: 'actionSuccess',
      action: 'communications_channel_set',
      message: undefined,
      reply: undefined,
      requestId: sendAction.mock.lastCall?.[2],
      envelope: { type: 'action_success', data: {} },
    })
  }
  function fail(reason: string): void {
    replies.emit('actionError', {
      kind: 'actionError',
      action: 'communications_channel_set',
      requestId: sendAction.mock.lastCall?.[2],
      reason,
      errorCode: undefined,
      errorType: undefined,
      errorDetail: undefined,
      envelope: { type: 'action_error', data: {} },
    })
  }

  return {
    edit,
    scopes,
    source,
    update: (next: unknown) => page.data.set('letterFrame', next),
    sendAction,
    run,
    refusals,
    succeed,
    fail,
  }
}

describe('channel frame edit window', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it.each(['default', 'project', 'settings'] as const)(
    'cannot save unchanged effective HTML from %s, including Enter',
    async (source) => {
      const { edit, run, sendAction } = harness(frame({ source }))
      expect(edit.open()).toBe(true)
      expect(edit.form.get()).toEqual({ html })
      expect(Object.keys(edit.state.get().fields)).toEqual(['html'])
      expect(edit.state.get().dirty).toBe(false)
      expect(edit.saveAvailable.get()).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)
      expect(sendAction).not.toHaveBeenCalled()
      expect(run).not.toHaveBeenCalled()
      expect(edit.opened.get()).toBe(false)
      edit.dispose()
    },
  )

  it('sends the exact HTML through the tracked runner and waits past its echo', async () => {
    const { edit, update, source, run, sendAction, succeed } = harness()
    edit.open()
    const draft = ` \n${mine}\n `
    edit.patchForm({ html: draft })
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.canSave.get()).toBe(true)
    expect(source.get()?.previewHtml).toBe(frame().previewHtml)
    const saving = edit.save(run)
    expect(sendAction).toHaveBeenCalledExactlyOnceWith(
      'communications_channel_set',
      { channel: 'email', field: 'frame', value: draft },
      expect.any(String),
    )
    expect(run).toHaveBeenCalledOnce()
    expect(run.mock.calls[0]?.[0].requestId).toBe(sendAction.mock.calls[0]?.[2])
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.open()).toBe(false)
    await edit.save(run)
    expect(sendAction).toHaveBeenCalledOnce()
    vi.advanceTimersByTime(300)
    expect(run.mock.calls[0]?.[0].loading.get()).toBe(true)

    update(frame({ html: draft, previewHtml: '<main>482913</main>' }))
    expect(source.get()?.previewHtml).toBe('<main>482913</main>')
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.saving.get()).toBe(true)
    expect(edit.opened.get()).toBe(true)
    succeed()
    await saving
    expect(edit.saving.get()).toBe(false)
    expect(edit.opened.get()).toBe(false)
    edit.dispose()
  })

  it('preserves a server refusal and draft, and permits a tracked retry', async () => {
    const { edit, source, run, fail, succeed, refusals, sendAction } = harness()
    edit.open()
    // Invalid HTML still leaves: validation belongs to the backend.
    edit.patchForm({ html: '<main>No content placeholder</main>' })
    const saving = edit.save(run)
    await Promise.resolve()
    expect(edit.opened.get()).toBe(true)
    expect(edit.saving.get()).toBe(true)
    const reason = 'The letter frame must contain [[:content]] exactly once.'
    fail(reason)
    await saving
    expect(refusals).toHaveLength(1)
    expect(refusals[0]).toBeInstanceOf(ActionError)
    expect((refusals[0] as ActionError).message).toBe(reason)
    expect(edit.opened.get()).toBe(true)
    expect(edit.saving.get()).toBe(false)
    expect(edit.form.get()).toEqual({
      html: '<main>No content placeholder</main>',
    })
    expect(source.get()?.html).toBe(html)
    expect(edit.canSave.get()).toBe(true)
    edit.setForm({ html: mine })
    const retry = edit.save(run)
    succeed()
    await retry
    expect(sendAction).toHaveBeenCalledTimes(2)
    expect(edit.opened.get()).toBe(false)
    edit.dispose()
  })

  it('accepts a live update and reset without a draft, advancing the HTML baseline', () => {
    const { edit, update } = harness()
    edit.open()
    update(frame({ html: theirs }))
    expect(edit.form.get()).toEqual({ html: theirs })
    expect(edit.noticeText.get()).toBe('Updated just now')
    expect(edit.canSave.get()).toBe(false)
    update(frame({ source: 'project', html: mine }))
    expect(edit.form.get()).toEqual({ html: mine })
    expect(edit.state.get().gone).toBe(false)
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.noticeText.get()).toBe('Updated just now')
    edit.patchForm({ html: theirs })
    expect(edit.noticeText.get()).toBe('')
    expect(edit.canSave.get()).toBe(true)
    edit.dispose()
  })

  it('ignores source and preview changes in the form and conflict verdict', () => {
    const { edit, update, source } = harness()
    edit.open()
    update(frame({ source: 'project', previewHtml: 'Project preview' }))
    expect(edit.form.get()).toEqual({ html })
    expect(edit.noticeText.get()).toBe('')
    expect(edit.state.get().dirty).toBe(false)
    edit.patchForm({ html: mine })
    // Reset can change just the source when the effective HTML is identical.
    update(frame({ source: 'default', previewHtml: HIDDEN_VALUE }))
    expect(source.get()?.previewHtml).toBe(HIDDEN_VALUE)
    expect(edit.form.get()).toEqual({ html: mine })
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.noticeText.get()).toBe('')
    expect(edit.canSave.get()).toBe(true)
    edit.dispose()
  })

  it.each(['project', 'default'] as const)(
    'merges a reset to %s under a draft through Keep mine and Take theirs',
    async (source) => {
      const { edit, update, run, sendAction } = harness()
      edit.open()
      edit.patchForm({ html: mine })
      update(frame({ source, html: theirs }))
      expect(edit.state.get().gone).toBe(false)
      expect(edit.state.get().conflict).toBe(true)
      expect(edit.state.get().fields.html.incoming).toBe(theirs)
      expect(edit.form.get()).toEqual({ html: mine })
      expect(edit.noticeText.get()).toBe('Frame HTML changed elsewhere.')
      expect(edit.noticeText.get()).not.toContain(theirs)
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)
      expect(sendAction).not.toHaveBeenCalled()
      expect(edit.opened.get()).toBe(true)
      edit.keepMine()
      expect(edit.form.get()).toEqual({ html: mine })
      expect(edit.state.get().conflict).toBe(false)
      expect(edit.canSave.get()).toBe(true)
      update(frame({ source, html: '<footer>[[:content]]</footer>' }))
      expect(edit.state.get().conflict).toBe(true)
      edit.takeTheirs()
      expect(edit.form.get()).toEqual({ html: '<footer>[[:content]]</footer>' })
      expect(edit.state.get().dirty).toBe(false)
      expect(edit.state.get().conflict).toBe(false)
      expect(edit.noticeText.get()).toBe('Updated just now')
      expect(edit.canSave.get()).toBe(false)
      edit.dispose()
    },
  )

  it('converges a matching remote HTML without closing before a tracked outcome', () => {
    const { edit, update } = harness()
    edit.open()
    edit.patchForm({ html: mine })
    update(frame({ source: 'project', html: mine }))
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
    edit.dispose()
  })

  it('refuses a hidden frame even after typing and Keep mine', async () => {
    const { edit, run, sendAction } = harness(frame({ html: HIDDEN_VALUE }))
    expect(edit.open()).toBe(true)
    expect(edit.form.get()).toEqual({ html: '' })
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ html: mine })
    edit.keepMine()
    await edit.save(run)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get()).toEqual({ html: mine })
    expect(sendAction).not.toHaveBeenCalled()
    edit.dispose()
  })

  it.each(['hidden', 'missing', 'malformed', 'scope cleared'])(
    'keeps the draft extractable and blocks both submit paths when %s',
    async (unavailable) => {
      const { edit, scopes, update, run, sendAction } = harness()
      edit.open()
      edit.patchForm({ html: mine })
      if (unavailable === 'hidden') update(frame({ html: HIDDEN_VALUE }))
      if (unavailable === 'missing') update(undefined)
      if (unavailable === 'malformed') update({ ...frame(), html: null })
      if (unavailable === 'scope cleared') scopes.dropPage()
      edit.keepMine()
      edit.takeTheirs()
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)
      expect(edit.opened.get()).toBe(true)
      expect(edit.form.get()).toEqual({ html: mine })
      expect(sendAction).not.toHaveBeenCalled()
      expect(run).not.toHaveBeenCalled()
      if (unavailable !== 'hidden') {
        expect(edit.saveLabel.get()).toBe('Deleted')
        expect(edit.noticeText.get()).toBe(
          'Deleted elsewhere — your text stays to copy.',
        )
      }
      edit.dispose()
    },
  )

  it('cannot open without a carrier or submit a closed window', async () => {
    const { edit, update, run, sendAction } = harness()
    update(undefined)
    expect(edit.open()).toBe(false)
    update(frame())
    edit.open()
    edit.patchForm({ html: mine })
    edit.close()
    await edit.save(run)
    expect(sendAction).not.toHaveBeenCalled()
    edit.dispose()
  })

  it('ignores a delayed success after the session closes and reopens', async () => {
    const { edit, run, succeed } = harness()
    edit.open()
    edit.patchForm({ html: mine })
    const saving = edit.save(run)
    edit.close()
    edit.open()
    edit.patchForm({ html: theirs })
    succeed()
    await saving
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get()).toEqual({ html: theirs })
    expect(edit.canSave.get()).toBe(true)
    edit.dispose()
  })
})
