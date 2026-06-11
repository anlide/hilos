import { describe, expect, it } from 'vitest'
import { z } from 'zod'
import { parseSignal } from './parseSignal.js'

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
})
