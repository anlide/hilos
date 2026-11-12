import { describe, expect, it, vi } from 'vitest'
import { registerLayoutPublicStatusAccountCases } from '../../test-support/layoutPublicStatusAccountCases.js'
import { registerLayoutPublicStatusColdCases } from '../../test-support/layoutPublicStatusColdCases.js'
import { registerLayoutPublicStatusInteractionCases } from '../../test-support/layoutPublicStatusInteractionCases.js'
import { registerLayoutPublicStatusOutletCases } from '../../test-support/layoutPublicStatusOutletCases.js'
import { mountLayoutPublicStatus } from './layoutPublicStatusMount.js'

registerLayoutPublicStatusColdCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)

registerLayoutPublicStatusAccountCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)

registerLayoutPublicStatusInteractionCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)

registerLayoutPublicStatusOutletCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutPublicStatus,
)
