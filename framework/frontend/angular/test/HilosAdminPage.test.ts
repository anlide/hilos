// Shared cases register over the real Angular shell and its native templates.
import { describe, expect, it } from 'vitest'
import { registerAdminPageLegacyCases } from '../../test-support/adminPageLegacyCases.js'
import { registerAdminPageHeadCases } from '../../test-support/adminPageHeadCases.js'
import { registerAdminPageContextCases } from '../../test-support/adminPageContextCases.js'
import { registerAdminPageDeletionCases } from '../../test-support/adminPageDeletionCases.js'
import { mountAdminPage } from './adminPageMount.js'

registerAdminPageLegacyCases(
  'Angular',
  { describe, it, expect },
  mountAdminPage,
)
registerAdminPageHeadCases('Angular', { describe, it, expect }, mountAdminPage)
registerAdminPageContextCases(
  'Angular',
  { describe, it, expect },
  mountAdminPage,
)

registerAdminPageDeletionCases(
  'Angular',
  { describe, it, expect },
  mountAdminPage,
)
