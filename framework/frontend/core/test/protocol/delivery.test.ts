import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
  unpackSignalFrames,
  parseSignalValue,
  SIGNAL_TYPE_SIGNAL_BATCH,
} from '../../src/base/index.js'

const first = {
  type: 'action_success',
  data: { action: 'save', reply: { id: 42 } },
  requestId: 'req-42',
  outcome: 'success',
  time: 123,
}
const last = { type: 'project_changed', data: { value: 'tail' } }

describe('unpackSignalFrames', () => {
  it('keeps the old bare envelope and all its metadata', () => {
    expect(unpackSignalFrames(JSON.stringify(first))).toEqual({
      ok: true,
      frames: [first],
    })
  })

  it('parses JSON once and keeps ordered unknown frames for independent validation', () => {
    const raw = JSON.stringify({
      type: SIGNAL_TYPE_SIGNAL_BATCH,
      data: { frames: [first, null, last] },
    })
    const parse = vi.spyOn(JSON, 'parse')
    try {
      const result = unpackSignalFrames(raw)
      expect(result).toEqual({ ok: true, frames: [first, null, last] })
      if (!result.ok) throw new Error('Expected a delivery')
      expect(
        result.frames.map((frame) =>
          parseSignalValue(frame, {
            project_changed: z.object({ value: z.string() }),
          }),
        ),
      ).toMatchObject([
        { ok: true, signal: { kind: 'actionSuccess', envelope: first } },
        { ok: false, failure: { kind: 'invalid-envelope' } },
        { ok: true, signal: { kind: 'project', data: last.data } },
      ])
      expect(parse).toHaveBeenCalledTimes(1)
    } finally {
      parse.mockRestore()
    }
  })

  it.each([
    undefined,
    null,
    [],
    {},
    { frames: [] },
    { frames: {} },
    { frames: 'x' },
  ])('rejects the whole malformed batch payload %j', (data) => {
    expect(
      unpackSignalFrames(
        JSON.stringify({ type: SIGNAL_TYPE_SIGNAL_BATCH, data }),
      ),
    ).toMatchObject({
      ok: false,
      failure: {
        kind: data === undefined ? 'invalid-envelope' : 'invalid-signal-data',
      },
    })
  })

  it.each([
    null,
    [],
    {},
    { type: '' },
    { type: 'signal_batch', data: { frames: [first] }, time: 1.5 },
  ])(
    'validates the outer envelope before touching inner frames: %j',
    (outer) => {
      expect(unpackSignalFrames(JSON.stringify(outer))).toMatchObject({
        ok: false,
        failure: { kind: 'invalid-envelope' },
      })
    },
  )

  it('keeps malformed JSON and binary diagnostics', () => {
    expect(unpackSignalFrames('{')).toEqual({
      ok: false,
      failure: { kind: 'malformed-json', raw: '{' },
    })
    for (const raw of [new ArrayBuffer(4), new Blob(['{}']), {}]) {
      expect(unpackSignalFrames(raw)).toEqual({
        ok: false,
        failure: { kind: 'non-text-frame' },
      })
    }
  })

  it('accepts one inner frame but never interprets a nested batch as a project signal', () => {
    const inner = { type: SIGNAL_TYPE_SIGNAL_BATCH, data: { frames: [first] } }
    expect(unpackSignalFrames(JSON.stringify(inner))).toEqual({
      ok: true,
      frames: [first],
    })
    const result = unpackSignalFrames(
      JSON.stringify({
        type: SIGNAL_TYPE_SIGNAL_BATCH,
        data: { frames: [inner, last] },
      }),
    )
    if (!result.ok) throw new Error('Expected a delivery')
    expect(
      result.frames.map((frame) =>
        parseSignalValue(frame, { signal_batch: z.unknown() }),
      ),
    ).toMatchObject([
      {
        ok: false,
        failure: {
          kind: 'invalid-signal-data',
          type: SIGNAL_TYPE_SIGNAL_BATCH,
        },
      },
      { ok: true, signal: { kind: 'unknown', type: last.type } },
    ])
  })
})
