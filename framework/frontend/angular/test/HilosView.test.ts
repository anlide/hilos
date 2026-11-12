// The outlet behavior is registered once and exercised through the real SDK.
import { describe, expect, it, vi } from 'vitest'
import { registerHilosViewDeletionCases } from '../../test-support/hilosViewDeletionCases.js'
import { withHilosViewDeletion } from './hilosViewDeletion.js'
import { registerHilosViewLazyCases } from '../../test-support/hilosViewLazyCases.js'
import { withHilosViewLazy } from './hilosViewLazy.js'
import { registerHilosViewStatusCases } from '../../test-support/hilosViewStatusCases.js'
import { registerHilosViewAccessCases } from '../../test-support/hilosViewAccessCases.js'
import { withHilosViewAccess } from './hilosViewAccess.js'
import { registerHilosViewOutletCases } from '../../test-support/hilosViewOutletCases.js'
import { withHilosViewOutlet } from './hilosViewOutlet.js'

registerHilosViewDeletionCases(
  'Angular',
  { describe, it, expect },
  withHilosViewDeletion,
)

registerHilosViewOutletCases(
  'Angular',
  { describe, it, expect, vi },
  withHilosViewOutlet,
)

registerHilosViewLazyCases(
  'Angular',
  { describe, it, expect, vi },
  withHilosViewLazy,
)

registerHilosViewStatusCases(
  'Angular',
  { describe, it, expect, vi },
  withHilosViewLazy,
)

registerHilosViewAccessCases(
  'Angular',
  { describe, it, expect, vi },
  withHilosViewAccess,
)
