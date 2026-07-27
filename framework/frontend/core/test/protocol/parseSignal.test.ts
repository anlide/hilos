import { describe, expect, it } from 'vitest'
import { z } from 'zod'
import { parseSignal } from '../../src/protocol/parseSignal.js'

describe('parseSignal', () => {
  it('rejects non-text frames', () => {
    const result = parseSignal(new ArrayBuffer(4))
    expect(result).toEqual({ ok: false, failure: { kind: 'non-text-frame' } })
  })

  it('rejects malformed JSON', () => {
    const result = parseSignal('{nope')
    expect(result).toEqual({
      ok: false,
      failure: { kind: 'malformed-json', raw: '{nope' },
    })
  })

  it('rejects frames violating the envelope shape', () => {
    // `data` is required at the envelope layer — the daemon always sends it.
    for (const raw of [
      '"just a string"',
      '42',
      '{}',
      '{"type":""}',
      '{"type":7,"data":{}}',
      '{"type":"handshake"}',
    ]) {
      const result = parseSignal(raw)
      expect(result.ok, raw).toBe(false)
      if (!result.ok) {
        expect(result.failure.kind, raw).toBe('invalid-envelope')
      }
    }
  })

  it('parses the framework welcome', () => {
    const result = parseSignal(
      '{"type":"handshake","data":{"build":"1718000000"}}',
    )
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal).toMatchObject({
        kind: 'handshake',
        build: '1718000000',
      })
    }
  })

  it('rejects a welcome with bad data as invalid-signal-data', () => {
    for (const raw of [
      '{"type":"handshake","data":{}}',
      '{"type":"handshake","data":{"build":""}}',
      '{"type":"handshake","data":{"build":3}}',
      '{"type":"handshake","data":[]}',
    ]) {
      const result = parseSignal(raw)
      expect(result.ok, raw).toBe(false)
      if (!result.ok) {
        expect(result.failure, raw).toMatchObject({
          kind: 'invalid-signal-data',
          type: 'handshake',
        })
      }
    }
  })

  it('parses signals with an unknown type instead of failing', () => {
    // Realistic: the chat demo's project-level welcome rides the same socket.
    const result = parseSignal(
      '{"type":"handshake_response","data":{"selfId":7,"pageCatalog":{}}}',
    )
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal).toMatchObject({
        kind: 'unknown',
        type: 'handshake_response',
      })
      expect(result.signal.envelope.data).toEqual({
        selfId: 7,
        pageCatalog: {},
      })
    }
  })

  it('tolerates envelope metadata and extra keys', () => {
    const result = parseSignal(
      '{"type":"message::success","data":{},"outcome":"success","time":1718000000000,"extra":"x"}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'unknown') {
      expect(result.signal.envelope.outcome).toBe('success')
      expect(result.signal.envelope.time).toBe(1718000000000)
    }
  })

  it('rejects an invalid outcome marker', () => {
    const result = parseSignal('{"type":"x","data":{},"outcome":"maybe"}')
    expect(result.ok).toBe(false)
  })

  it('parses a type with a project schema as a project signal', () => {
    const result = parseSignal('{"type":"greet","data":{"who":"hilos"}}', {
      greet: z.looseObject({ who: z.string() }),
    })
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal).toMatchObject({
        kind: 'project',
        type: 'greet',
        data: { who: 'hilos' },
      })
    }
  })

  it('rejects a project signal violating its schema', () => {
    const result = parseSignal('{"type":"greet","data":{"who":7}}', {
      greet: z.looseObject({ who: z.string() }),
    })
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.failure).toMatchObject({
        kind: 'invalid-signal-data',
        type: 'greet',
      })
    }
  })

  it('keeps framework types ahead of a shadowing project schema', () => {
    const result = parseSignal(
      '{"type":"handshake","data":{"build":"1718000000"}}',
      { handshake: z.looseObject({ hijacked: z.string() }) },
    )
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal.kind).toBe('handshake')
    }
  })

  it('parses an action_error frame as a framework signal without registration', () => {
    const result = parseSignal(
      '{"type":"action_error","data":{"action":"message","reason":"Message rate limit is active"},"outcome":"fail"}',
    )
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal).toMatchObject({
        kind: 'actionError',
        action: 'message',
        reason: 'Message rate limit is active',
      })
      expect(result.signal.envelope.outcome).toBe('fail')
      if (result.signal.kind === 'actionError') {
        expect(result.signal.errorCode).toBeUndefined()
      }
    }
  })

  it('surfaces the machine-readable errorCode on an action_error frame', () => {
    const result = parseSignal(
      '{"type":"action_error","data":{"action":"message","reason":"Authentication required","errorCode":"unauthorized"},"outcome":"fail"}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'actionError') {
      expect(result.signal.errorCode).toBe('unauthorized')
    }
  })

  it('rejects an action_error frame missing its reason', () => {
    const result = parseSignal(
      '{"type":"action_error","data":{"action":"message"}}',
    )
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.failure).toMatchObject({
        kind: 'invalid-signal-data',
        type: 'action_error',
      })
    }
  })

  it('parses an action_success frame and surfaces the correlating requestId', () => {
    const result = parseSignal(
      '{"type":"action_success","data":{"action":"moderator_piece_update"},"outcome":"success","requestId":"req-9"}',
    )
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal).toMatchObject({
        kind: 'actionSuccess',
        action: 'moderator_piece_update',
        message: undefined,
        requestId: 'req-9',
      })
      expect(result.signal.envelope.outcome).toBe('success')
    }
  })

  it('surfaces the backend success message on an action_success frame', () => {
    const result = parseSignal(
      '{"type":"action_success","data":{"action":"moderator_piece_update","message":"Piece approved."},"outcome":"success","requestId":"req-9"}',
    )
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.signal).toMatchObject({
        kind: 'actionSuccess',
        action: 'moderator_piece_update',
        message: 'Piece approved.',
      })
    }
  })

  it('echoes the requestId on an action_error reply', () => {
    const result = parseSignal(
      '{"type":"action_error","data":{"action":"message","reason":"boom"},"outcome":"fail","requestId":"req-4"}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'actionError') {
      expect(result.signal.requestId).toBe('req-4')
    }
  })

  it('parses a table_window frame as a framework signal', () => {
    const result = parseSignal(
      '{"type":"table_window","data":{"page":"hilos_settings","tableKey":"settings","rows":[{"rowKey":"theme","slots":{"settings":{"key":"theme"}}}],"totalCount":12,"offset":0,"limit":10}}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'tableWindow') {
      expect(result.signal.data).toMatchObject({
        page: 'hilos_settings',
        tableKey: 'settings',
        totalCount: 12,
        offset: 0,
        limit: 10,
      })
      expect(result.signal.data.rows[0]).toEqual({
        rowKey: 'theme',
        slots: { settings: { key: 'theme' } },
      })
    }
  })

  it('rejects a table_window frame missing its metadata', () => {
    const result = parseSignal(
      '{"type":"table_window","data":{"tableKey":"settings","rows":[]}}',
    )
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.failure).toMatchObject({
        kind: 'invalid-signal-data',
        type: 'table_window',
      })
    }
  })

  it('parses a table_viewport_delta row_updated frame', () => {
    const result = parseSignal(
      '{"type":"table_viewport_delta","data":{"page":"hilos_settings","tableKey":"settings","kind":"row_updated","rowKey":"theme","row":{"rowKey":"theme","slots":{"settings":{"key":"theme"}}}}}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'tableViewportDelta') {
      expect(result.signal.data.kind).toBe('row_updated')
      expect(result.signal.data.rowKey).toBe('theme')
      expect(result.signal.data.row).toEqual({
        rowKey: 'theme',
        slots: { settings: { key: 'theme' } },
      })
    }
  })

  it('parses a table_viewport_count frame', () => {
    const result = parseSignal(
      '{"type":"table_viewport_count","data":{"page":"p","tableKey":"t","totalCount":3,"pageCount":1}}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'tableViewportCount') {
      expect(result.signal.data).toMatchObject({
        totalCount: 3,
        pageCount: 1,
      })
    }
  })

  it('parses a table_viewport_append frame', () => {
    const result = parseSignal(
      '{"type":"table_viewport_append","data":{"page":"p","tableKey":"t","row":{"rowKey":"x","slots":{}},"totalCount":4,"pageCount":1}}',
    )
    expect(result.ok).toBe(true)
    if (result.ok && result.signal.kind === 'tableViewportAppend') {
      expect(result.signal.data).toMatchObject({
        row: { rowKey: 'x', slots: {} },
        totalCount: 4,
        pageCount: 1,
      })
    }
  })
})
