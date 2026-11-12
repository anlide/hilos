import { describe, expect, it } from 'vitest'
import { registerDashboardCases } from '../../test-support/dashboardCases.js'
import { mountDashboard } from './dashboardMount.js'

registerDashboardCases('Angular', { describe, it, expect }, mountDashboard)
