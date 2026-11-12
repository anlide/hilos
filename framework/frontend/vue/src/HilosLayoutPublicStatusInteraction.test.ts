// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { registerLayoutPublicStatusInteractionCases } from '../../test-support/layoutPublicStatusInteractionCases.js'
import { mountLayoutPublicStatus } from '../test/layoutPublicStatusMount.js'

registerLayoutPublicStatusInteractionCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)
