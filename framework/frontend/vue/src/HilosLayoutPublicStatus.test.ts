import { describe, expect, it, vi } from 'vitest'
import { registerLayoutPublicStatusAccountCases } from '../../test-support/layoutPublicStatusAccountCases.js'
import { registerLayoutPublicStatusColdCases } from '../../test-support/layoutPublicStatusColdCases.js'
import { registerLayoutPublicStatusOutletCases } from '../../test-support/layoutPublicStatusOutletCases.js'
import { mountLayoutPublicStatus } from '../test/layoutPublicStatusMount.js'

registerLayoutPublicStatusColdCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)

registerLayoutPublicStatusAccountCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)

registerLayoutPublicStatusOutletCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)
