// @vitest-environment jsdom
// Inherited :disabled needs jsdom; the other Layout scenarios keep happy-dom.
import { describe, expect, it } from 'vitest'
import { registerLayoutConnectionPageCases } from '../../test-support/layoutConnectionPageCases.js'
import { registerLayoutConnectionModalCases } from '../../test-support/layoutConnectionModalCases.js'
import { registerLayoutConnectionContextCases } from '../../test-support/layoutConnectionContextCases.js'
import {
  mountLayoutConnection,
  mountStandaloneConnection,
} from '../test/layoutConnectionMount.js'

registerLayoutConnectionPageCases(
  'Vue',
  { describe, it, expect },
  mountLayoutConnection,
)

registerLayoutConnectionModalCases(
  'Vue',
  { describe, it, expect },
  mountLayoutConnection,
)

registerLayoutConnectionContextCases(
  'Vue',
  { describe, it, expect },
  mountLayoutConnection,
  mountStandaloneConnection,
)
