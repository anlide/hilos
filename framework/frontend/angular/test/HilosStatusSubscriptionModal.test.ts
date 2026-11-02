import { STATUS_SUBSCRIPTION_COPY as COPY } from '@hilos/core/status'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosStatusPage } from '../src/public/HilosStatusPage.js'
import {
  el,
  query,
  text,
  enter,
  setText,
  mountPublicStatus,
  publicStatusFile,
} from './helpers/hilosStatus.js'

const token = 'ab'.repeat(32)
function mount(fragment = '', connected = true, frozen = false) {
  window.history.replaceState({}, '', `/status${fragment}`)
  return mountPublicStatus(
    HilosStatusPage,
    publicStatusFile(),
    connected,
    frozen,
  )
}
const formSubmit = () =>
  el('status-subscription-form').dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
const primary = () => el<HTMLButtonElement>('status-subscription-submit')
const email = () => el<HTMLInputElement>('status-subscription-email')
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('Angular Status subscription over the real modal and lifecycle', () => {
  it('labels the email, traps focus and returns it to the opener without dispatching an empty draft', async () => {
    const h = mount()
    await h.settle()
    const opener = el('public-status-subscribe')
    h.act(() => {
      opener.focus()
      opener.click()
    })
    expect(el('modal').getAttribute('role')).toBe('dialog')
    expect(el('modal').getAttribute('aria-label')).toBe(COPY.subscribe)
    expect(document.activeElement).toBe(email())
    expect(email().type).toBe('email')
    expect(email().autocomplete).toBe('email')
    expect(
      document.querySelector(`label[for="${email().id}"]`)?.textContent,
    ).toContain('Email address')
    for (const id of email().getAttribute('aria-describedby')!.split(' '))
      expect(document.getElementById(id)).not.toBeNull()
    expect(el('status-subscription-form').hasAttribute('novalidate')).toBe(true)
    expect(primary().disabled).toBe(true)
    h.act(formSubmit)
    expect(h.sent).toEqual([])
    h.act(() => setText('status-subscription-email', 'Reader@Example.net'))
    h.act(() => {
      primary().focus()
      primary().dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Tab',
          bubbles: true,
          cancelable: true,
        }),
      )
    })
    expect(document.activeElement).toBe(el('modal-close'))
    h.act(() =>
      el('modal').dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Escape',
          bubbles: true,
          cancelable: true,
        }),
      ),
    )
    expect(query('modal')).toBeNull()
    expect(document.activeElement).toBe(opener)
  })

  it('submits Enter once, keeps the draft mounted while busy and rejects further submits or close gestures', async () => {
    const h = mount()
    await h.settle()
    h.act(() => el('public-status-subscribe').click())
    const field = email()
    h.act(() => setText('status-subscription-email', 'Reader@Example.net'))
    h.act(() => enter(field))
    expect(h.sent).toEqual([
      {
        action: 'status_subscribe',
        data: { email: 'reader@example.net' },
        requestId: expect.any(String),
      },
    ])
    expect(primary().getAttribute('aria-busy')).toBe('true')
    expect(field.matches(':disabled')).toBe(true)
    h.act(() => {
      primary().click()
      formSubmit()
      enter(field)
      el('modal').dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Escape',
          bubbles: true,
          cancelable: true,
        }),
      )
    })
    expect(h.sent).toHaveLength(1)
    expect(email()).toBe(field)
    expect(field.value).toBe('Reader@Example.net')
    expect(el<HTMLButtonElement>('status-subscription-cancel').disabled).toBe(
      true,
    )
    h.reply(true, { reply: { status: 'check_inbox' } })
    await h.settle()
    expect(query('modal')).toBeNull()
    expect(text('public-status-subscription-notice')).toBe(COPY.checkInbox)
  })

  it('retains an extractable address and the server refusal, then retries through the same submit verdict', async () => {
    const h = mount()
    await h.settle()
    h.act(() => el('public-status-subscribe').click())
    h.act(() => setText('status-subscription-email', 'bad@example.net'))
    const field = email()
    h.act(formSubmit)
    h.reply(false, { reason: 'This address cannot receive mail.' })
    await h.settle()
    expect(text('status-subscription-error')).toContain(
      'This address cannot receive mail.',
    )
    expect(email()).toBe(field)
    expect(field.value).toBe('bad@example.net')
    expect(field.getAttribute('aria-invalid')).toBe('true')
    h.act(() => {
      setText('status-subscription-email', 'good@example.net')
      formSubmit()
    })
    h.reply(true, { reply: { status: 'check_inbox' } })
    await h.settle()
    expect(query('modal')).toBeNull()
  })

  it('previews a scrubbed unsubscribe capability without spending it and Keep leaves it unspent', async () => {
    const h = mount(`#status-unsubscribe=${token}`)
    await h.settle()
    expect(window.location.hash).toBe('')
    expect(h.sent.map((request) => request.action)).toEqual([
      'status_unsubscribe_preview',
    ])
    expect(query('status-subscription-submit')).toBeNull()
    h.reply(true, { reply: { maskedEmail: 'r***@example.net' } })
    await h.settle()
    expect(text('status-subscription-address')).toContain('r***@example.net')
    expect(document.activeElement).toBe(el('modal'))
    h.act(() => el('status-subscription-cancel').click())
    expect(query('modal')).toBeNull()
    expect(h.sent).toHaveLength(1)
  })

  it('spends an unsubscribe capability only after explicit confirmation and leaves its outcome on the page', async () => {
    const h = mount(`#status-unsubscribe=${token}`)
    await h.settle()
    h.reply(true, { reply: { maskedEmail: 'r***@example.net' } })
    await h.settle()
    h.act(() => primary().click())
    expect(h.sent[1]).toMatchObject({
      action: 'status_unsubscribe',
      data: { token },
    })
    h.reply(true, { reply: { status: 'unsubscribed' } })
    await h.settle()
    expect(query('modal')).toBeNull()
    expect(text('public-status-subscription-notice')).toBe(COPY.unsubscribed)
    expect(h.fetchFile.mock.calls.every(([url]) => !url.includes(token))).toBe(
      true,
    )
  })

  it('holds the waiting link under freeze, blocks offline form sends and ignores a reply after departure', async () => {
    const h = mount(`#status-unsubscribe=${token}`, false, true)
    await h.settle()
    expect(text('status-subscription-progress')).toBe(COPY.waiting)
    expect(h.sent).toEqual([])
    h.act(() => h.connect('connected'))
    expect(h.sent).toEqual([])
    h.act(() => h.freeze(false))
    h.reply(true, { reply: { maskedEmail: 'r***@example.net' } })
    await h.settle()
    h.act(() => h.connect('disconnected'))
    h.act(formSubmit)
    expect(primary().disabled).toBe(true)
    expect(h.sent).toHaveLength(1)
    h.act(() => h.connect('connected'))
    h.act(formSubmit)
    expect(h.sent).toHaveLength(2)
    h.fixture.destroy()
    h.fixture.nativeElement.remove()
    h.reply(true, { reply: { status: 'unsubscribed' } })
    await h.settle()
    expect(query('modal')).toBeNull()
    expect(query('public-status-subscription-notice')).toBeNull()
  })
})
