// Pins the public surface: everything an Angular consumer may import from
// '@hilos/angular' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  ConflictActions,
  ConflictHeader,
  HILOS_ROUTER,
  HilosAdminPage,
  HilosBreadcrumb,
  HilosDashboardPage,
  HilosLayout,
  HilosLink,
  HilosModal,
  HilosSettingsPage,
  HilosUserPage,
  HilosUsersPage,
  HilosView,
  HilosViewportTable,
  LoadingButton,
  connectionStateSignal,
  createHilosTrackedAction,
} from '../src/index.js'

it('exports the @hilos/angular public surface', () => {
  expect(connectionStateSignal).toBeTypeOf('function')
  expect(HILOS_ROUTER).toBeTypeOf('object')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('function')
  expect(LoadingButton).toBeTypeOf('function')
  expect(HilosBreadcrumb).toBeTypeOf('function')
  expect(ConflictHeader).toBeTypeOf('function')
  expect(ConflictActions).toBeTypeOf('function')
  expect(HilosAdminPage).toBeTypeOf('function')
  expect(HilosDashboardPage).toBeTypeOf('function')
  expect(HilosModal).toBeTypeOf('function')
  expect(HilosViewportTable).toBeTypeOf('function')
  expect(HilosSettingsPage).toBeTypeOf('function')
  expect(HilosUsersPage).toBeTypeOf('function')
  expect(HilosUserPage).toBeTypeOf('function')
  expect(createHilosTrackedAction).toBeTypeOf('function')
})
