import { describe, expect, it } from 'vitest'
import { eventPayloadToEvent, parseEventPayloads } from '@/entities/parsers'

describe('event entity payload parsers', () => {
  it('maps top-level event attachments', () => {
    const payloads = parseEventPayloads([{
      id: 9,
      type: 'message_sent',
      timestamp: '2026-05-01 10:00:00',
      eventMessage: {
        eventId: 9,
        message: 'with file',
        authorUserId: 7,
        authorBotId: null,
      },
      eventUserRegistration: null,
      eventUserRename: null,
      attachments: [{
        id: 11,
        eventId: 9,
        filename: 'report.pdf',
        mimeType: 'application/pdf',
      }],
    }])

    expect(payloads).not.toBeNull()
    const event = eventPayloadToEvent(payloads![0])

    expect(event.eventMessage?.message).toBe('with file')
    expect(event.eventMessage?.authorUserId).toBe(7)
    expect(event.attachments).toEqual([{
      id: 11,
      eventId: 9,
      filename: 'report.pdf',
      mimeType: 'application/pdf',
    }])
  })

  it('rejects backend-only attachment storage fields', () => {
    expect(parseEventPayloads([{
      id: 9,
      type: 'message_sent',
      timestamp: '2026-05-01 10:00:00',
      eventMessage: {
        eventId: 9,
        message: 'with file',
        authorUserId: 7,
        authorBotId: null,
      },
      attachments: [{
        id: 11,
        eventId: 9,
        filename: 'report.pdf',
        mimeType: 'application/pdf',
        storedName: 'secret.pdf',
      }],
    }])).toBeNull()
  })

  it('rejects legacy json payload fields', () => {
    expect(parseEventPayloads([{
      id: 9,
      userId: 7,
      type: 'message_sent',
      timestamp: '2026-05-01 10:00:00',
      data: { message: 'with file' },
    }])).toBeNull()
  })

  it('rejects flattened detail fields on events', () => {
    expect(parseEventPayloads([{
      id: 9,
      type: 'message_sent',
      timestamp: '2026-05-01 10:00:00',
      message: 'with file',
      authorUserId: 7,
    }])).toBeNull()
  })
})
