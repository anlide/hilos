import { describe, expect, it, vi } from 'vitest'
import { copyToClipboard } from '../../src/dom/clipboard.js'

describe('copyToClipboard', () => {
  it('resolves true and writes the given text when the clipboard accepts it', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)

    await expect(
      copyToClipboard('hilos restore --archive x', {
        writeText,
      } as unknown as Clipboard),
    ).resolves.toBe(true)
    expect(writeText).toHaveBeenCalledWith('hilos restore --archive x')
  })

  it('resolves false without rejecting when the clipboard refuses', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('no secure context'))

    await expect(
      copyToClipboard('text', { writeText } as unknown as Clipboard),
    ).resolves.toBe(false)
  })

  it('resolves false and never calls writeText when no clipboard is available', async () => {
    const writeText = vi.fn()

    await expect(copyToClipboard('text', undefined)).resolves.toBe(false)
    expect(writeText).not.toHaveBeenCalled()
  })
})
