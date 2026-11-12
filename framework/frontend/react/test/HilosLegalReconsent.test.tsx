import { describe, expect, it, vi } from 'vitest'
import { registerLegalReconsentAcceptCases } from '../../test-support/legalReconsentAcceptCases.js'
import { registerLegalReconsentContentCases } from '../../test-support/legalReconsentContentCases.js'
import { registerLegalReconsentNonWindowBusyCases } from '../../test-support/legalReconsentNonWindowBusyCases.js'
import { registerLegalReconsentPresentationCases } from '../../test-support/legalReconsentPresentationCases.js'
import { registerLegalReconsentPreviewCases } from '../../test-support/legalReconsentPreviewCases.js'
import { registerLegalReconsentWindowBusyCases } from '../../test-support/legalReconsentWindowBusyCases.js'
import { mountLegalReconsent } from './legalReconsentMount.js'

registerLegalReconsentPreviewCases(
  'React',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentPresentationCases(
  'React',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentContentCases(
  'React',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentAcceptCases(
  'React',
  { describe, it, expect, vi },
  mountLegalReconsent,
)

registerLegalReconsentNonWindowBusyCases(
  'React',
  { describe, it, expect },
  mountLegalReconsent,
)

registerLegalReconsentWindowBusyCases(
  'React',
  { describe, it, expect },
  mountLegalReconsent,
)
