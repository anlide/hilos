// @vitest-environment jsdom
import { describe, it, expect } from 'vitest'
import { registerLegalReconsentWindowBusyCases } from '../../../test-support/legalReconsentWindowBusyCases.js'
import { mountLegalReconsent } from '../../test/legalReconsentMount.js'

registerLegalReconsentWindowBusyCases(
  'Vue',
  { describe, it, expect },
  mountLegalReconsent,
)
