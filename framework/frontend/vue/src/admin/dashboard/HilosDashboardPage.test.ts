import { describe, expect, it } from 'vitest'
import { registerDashboardCases } from '../../../../test-support/dashboardCases.js'
import { mountDashboard } from '../../../test/dashboardMount.js'

registerDashboardCases('Vue', { describe, it, expect }, mountDashboard)
