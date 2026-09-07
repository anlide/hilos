// The test wiring every @hilos/angular test file gets, set up once per file.
//
// @angular/compiler is loaded because nothing here is compiled ahead of time:
// the tests run against source, so a component's template is compiled JIT at
// mount, and the public barrel re-exports partially-compiled declarables whose
// injectables fall back to that same compiler when the surface test imports them.
//
// TestBed's environment is initialized here rather than in a test file because
// the platform is per module registry, not per test — and the module reset is
// global for the same reason it cannot be per file: without it the second case
// anywhere hits "Cannot configure the test module when the test module has
// already been instantiated", and it is also what destroys the fixtures a case
// left alive. A new component test of this package configures nothing.
import '@angular/compiler'

import { TestBed } from '@angular/core/testing'
import {
  BrowserTestingModule,
  platformBrowserTesting,
} from '@angular/platform-browser/testing'
import { afterEach } from 'vitest'

TestBed.initTestEnvironment(BrowserTestingModule, platformBrowserTesting())

afterEach(() => {
  TestBed.resetTestingModule()
})
