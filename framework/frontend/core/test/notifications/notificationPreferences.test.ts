import { describe, expect, it } from 'vitest'
import {
  createHilosNotificationPreferencesStore,
  notificationPreferencesSectionSchema,
  type HilosNotificationPreferencesSection,
} from '../../src/notifications/notificationPreferences.js'

/** A two-channel section: email (allowed, addressed) and sms (muted, no address). */
function sampleSection(): HilosNotificationPreferencesSection {
  return {
    channels: [
      { channel: 'email', label: 'Email', allowed: true, hasAddress: true },
      { channel: 'sms', label: 'SMS', allowed: false, hasAddress: false },
    ],
    mandatoryNote: true,
  }
}

describe('notification preferences store', () => {
  it('applies a section snapshot to the reactive rows and note', () => {
    const store = createHilosNotificationPreferencesStore()

    store.applySection(sampleSection())

    expect(store.channels.get().map((row) => row.channel)).toEqual([
      'email',
      'sms',
    ])
    expect(store.mandatoryNote.get()).toBe(true)
    expect(store.channels.get()[0].allowed).toBe(true)
    expect(store.channels.get()[1].hasAddress).toBe(false)
  })

  it('flips allowed for listed channels and leaves the rest and the note untouched', () => {
    const store = createHilosNotificationPreferencesStore()
    store.applySection(sampleSection())

    // Only email is togglable (sms has no address), so the fanned map carries it alone.
    store.applyChangedMap({ email: false })

    const byChannel = new Map(
      store.channels.get().map((row) => [row.channel, row]),
    )
    expect(byChannel.get('email')?.allowed).toBe(false)
    // sms is absent from the map: its state and the section shape are preserved.
    expect(byChannel.get('sms')?.allowed).toBe(false)
    expect(store.channels.get()).toHaveLength(2)
    expect(store.mandatoryNote.get()).toBe(true)
  })

  it('ignores a changed-map key with no matching row', () => {
    const store = createHilosNotificationPreferencesStore()
    store.applySection(sampleSection())

    store.applyChangedMap({ push: true })

    expect(store.channels.get().map((row) => row.channel)).toEqual([
      'email',
      'sms',
    ])
  })

  it('tracks a per-channel pending toggle and settles it on the changed map', () => {
    const store = createHilosNotificationPreferencesStore()
    store.applySection(sampleSection())

    store.markPending('email')
    expect(store.pending.get().has('email')).toBe(true)

    store.applyChangedMap({ email: false })
    expect(store.pending.get().has('email')).toBe(false)
  })

  it('markPending is idempotent and applySection clears every pending toggle', () => {
    const store = createHilosNotificationPreferencesStore()
    store.applySection(sampleSection())

    store.markPending('email')
    store.markPending('email')
    expect(store.pending.get().size).toBe(1)

    store.applySection(sampleSection())
    expect(store.pending.get().size).toBe(0)
  })

  it('clearPending settles a rejected toggle without changing the channel state', () => {
    const store = createHilosNotificationPreferencesStore()
    store.applySection(sampleSection())

    store.markPending('email')
    store.clearPending('email')

    expect(store.pending.get().has('email')).toBe(false)
    expect(store.channels.get()[0].allowed).toBe(true)
  })

  it('clear resets the rows, note and pending', () => {
    const store = createHilosNotificationPreferencesStore()
    store.applySection(sampleSection())
    store.markPending('email')

    store.clear()

    expect(store.channels.get()).toEqual([])
    expect(store.mandatoryNote.get()).toBe(false)
    expect(store.pending.get().size).toBe(0)
  })

  it('parses a wire section payload with the exported schema', () => {
    const parsed = notificationPreferencesSectionSchema.parse({
      channels: [
        { channel: 'email', label: 'Email', allowed: true, hasAddress: true },
      ],
      mandatoryNote: false,
    })

    expect(parsed.channels).toHaveLength(1)
    expect(parsed.channels[0].channel).toBe('email')
    expect(parsed.channels[0].config).toBeUndefined()
    expect(parsed.mandatoryNote).toBe(false)
  })

  it('parses a channel row carrying frontend opt-in config', () => {
    const parsed = notificationPreferencesSectionSchema.parse({
      channels: [
        {
          channel: 'push',
          label: 'Push',
          allowed: true,
          hasAddress: false,
          config: { vapid_public: 'BPk...' },
        },
      ],
      mandatoryNote: false,
    })

    expect(parsed.channels[0].config).toEqual({ vapid_public: 'BPk...' })
  })
})
