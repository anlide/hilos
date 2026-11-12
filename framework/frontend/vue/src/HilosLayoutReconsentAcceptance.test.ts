// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { registerLayoutReconsentAcceptanceCases } from '../../test-support/layoutReconsentAcceptanceCases.js'
import { mountLayoutChrome } from '../test/layoutChromeMount.js'

registerLayoutReconsentAcceptanceCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)
