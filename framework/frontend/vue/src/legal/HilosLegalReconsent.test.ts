import { describe, expect, it, vi } from 'vitest'
import { registerLegalReconsentAcceptCases } from '../../../test-support/legalReconsentAcceptCases.js'
import { registerLegalReconsentContentCases } from '../../../test-support/legalReconsentContentCases.js'
import { registerLegalReconsentNonWindowBusyCases } from '../../../test-support/legalReconsentNonWindowBusyCases.js'
import { registerLegalReconsentPresentationCases } from '../../../test-support/legalReconsentPresentationCases.js'
import { registerLegalReconsentPreviewCases } from '../../../test-support/legalReconsentPreviewCases.js'
import { mountLegalReconsent } from '../../test/legalReconsentMount.js'

registerLegalReconsentPreviewCases(
  'Vue',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentPresentationCases(
  'Vue',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentContentCases(
  'Vue',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentAcceptCases(
  'Vue',
  { describe, it, expect, vi },
  mountLegalReconsent,
)

registerLegalReconsentNonWindowBusyCases(
  'Vue',
  { describe, it, expect },
  mountLegalReconsent,
)
