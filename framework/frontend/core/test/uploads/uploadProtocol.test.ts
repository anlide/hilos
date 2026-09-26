import { describe, expect, it } from 'vitest'

import {
  mintUploadId,
  signUploadChunk,
  uploadStateSchema,
} from '../../src/uploads/uploadProtocol.js'

describe('upload id', () => {
  it('mints distinct 32-character lowercase hex ids', () => {
    const first = mintUploadId()
    const second = mintUploadId()

    expect(first).toMatch(/^[0-9a-f]{32}$/)
    expect(second).toMatch(/^[0-9a-f]{32}$/)
    expect(second).not.toBe(first)
  })
})

describe('upload chunk frame', () => {
  it('prefixes the bytes with the id length and ASCII id', () => {
    const frame = new Uint8Array(
      signUploadChunk('upload_1', new Uint8Array([11, 22, 33]).buffer),
    )

    expect(frame[0]).toBe(8)
    expect(new TextDecoder().decode(frame.slice(1, 9))).toBe('upload_1')
    expect(frame.slice(9)).toEqual(new Uint8Array([11, 22, 33]))
  })

  it('signs an empty byte payload', () => {
    const frame = new Uint8Array(signUploadChunk('a', new ArrayBuffer(0)))

    expect(frame).toEqual(new Uint8Array([1, 97]))
  })

  it.each(['', 'a'.repeat(65), 'bad|id'])(
    'refuses an invalid id %j',
    (clientUploadId) => {
      expect(() => signUploadChunk(clientUploadId, new ArrayBuffer(0))).toThrow(
        'Invalid upload id',
      )
    },
  )
})

describe('upload state frame', () => {
  it('parses the complete state reported by the server', () => {
    expect(
      uploadStateSchema.parse({
        clientUploadId: 'upload-1',
        phase: 'uploading',
        receivedBytes: 65_536,
        declaredSize: 70_000,
        errorCode: null,
        errorMessage: null,
      }),
    ).toEqual({
      clientUploadId: 'upload-1',
      phase: 'uploading',
      receivedBytes: 65_536,
      declaredSize: 70_000,
      errorCode: null,
      errorMessage: null,
    })
  })

  it('defaults absent state fields to null for a gone upload', () => {
    expect(uploadStateSchema.parse({ clientUploadId: 'upload-1' })).toEqual({
      clientUploadId: 'upload-1',
      phase: null,
      receivedBytes: null,
      declaredSize: null,
      errorCode: null,
      errorMessage: null,
    })
  })
})
