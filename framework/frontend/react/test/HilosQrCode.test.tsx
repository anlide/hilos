// React mounting and cleanup for the shared QR drawing contract.
import { cleanup, render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  registerQrCodeCases,
  type WithQrCode,
} from '../../test-support/qrCodeCases.js'
import { HilosQrCode } from '../src/HilosQrCode.js'

/** Clean up React's roots even when rendering or the case throws. */
const withQrCode: WithQrCode = async (input, steps) => {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    render(<HilosQrCode {...input} />, { container })
    steps(container)
  } finally {
    try {
      cleanup()
    } finally {
      container.remove()
    }
  }
}

registerQrCodeCases('React', { describe, it, expect }, withQrCode)
