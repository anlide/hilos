import { test as base } from '@playwright/test'

import { withAdaptiveTimeouts } from '../../../../framework/frontend/e2e/adaptiveTimeouts.js'

export const test = withAdaptiveTimeouts(base)
export { expect } from '@playwright/test'
