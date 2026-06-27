import { NgComponentOutlet } from '@angular/common'
import { Component, type Type } from '@angular/core'
import {
  bootstrapApplication,
  type BootstrapContext,
} from '@angular/platform-browser'

// The build's `server` entry: the bootstrap renderApplication invokes for each
// public page (docs/agents/frontend/build-and-docker.md). The page component is
// handed in through a module variable the runner (server.ts, the ssr entry) sets
// before each render — single process, sequential, so no DI plumbing is needed.
// Kept free of top-level work so importing it for the server manifest has no
// side effects; the actual rendering loop lives in the runner.
let currentPage: Type<unknown> | null = null

export function setPrerenderPage(component: Type<unknown>): void {
  currentPage = component
}

// The SSR root: a bare outlet that renders the current public component into the
// index.html <app-root>. The real client app (src/app/app.ts) is a separate
// bootstrap that routes through HilosRouter, never this shell.
@Component({
  selector: 'app-root',
  imports: [NgComponentOutlet],
  template: '<ng-container *ngComponentOutlet="page" />',
})
export class PrerenderRoot {
  protected get page(): Type<unknown> | null {
    return currentPage
  }
}

export default function bootstrap(context: BootstrapContext) {
  return bootstrapApplication(PrerenderRoot, { providers: [] }, context)
}
