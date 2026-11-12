import { Component, InjectionToken, inject, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type {
  AdminPageInput,
  AdminPageView,
} from '../../test-support/adminPageFixtures.js'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HILOS_ADMIN_VIEW_MODE, injectLookOnly } from '../src/hilosLookOnly.js'
import { HILOS_PAGE_ERROR_BOUNDARY } from '../src/hilosPageErrorBoundaryToken.js'
import { HILOS_PAGE_HEADING_ID } from '../src/hilosPageHeadingToken.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const MODALS_MOUNTED = new InjectionToken<() => void>('AdminPageModalsMounted')

/** Created by the shell's real TemplateRef outlet, beneath its providers. */
@Component({
  selector: 'test-admin-modals-probe',
  template: `<span data-id="modals-probe"
    >{{ headingId }} {{ viewMode() }}</span
  >`,
})
class ModalsProbe {
  protected readonly headingId = inject(HILOS_PAGE_HEADING_ID)
  protected readonly viewMode = inject(HILOS_ADMIN_VIEW_MODE)

  constructor() {
    inject(MODALS_MOUNTED)()
  }
}

/** Caller-supplied held content uses the shell's native template API. */
@Component({
  selector: 'test-admin-held-host',
  imports: [HilosAdminPage, ModalsProbe],
  template: `
    @if (input(); as state) {
      @if (state.deletion; as deletion) {
        <hilos-admin-page
          [page]="state.page"
          [preserveModalDraftsOnDelete]="deletion.preserve"
        >
          <ng-template #pageBody>
            <button type="button" data-id="opener">Rename</button>
          </ng-template>
          <ng-template #pageModals>
            <test-admin-modals-probe />
          </ng-template>
        </hilos-admin-page>
      }
    }
  `,
})
class HeldAdminPageHost {
  readonly input = signal<AdminPageInput | null>(null)
}

/** Injection happens beneath the shell, inside its projected child. */
@Component({
  selector: 'test-admin-heading-probe',
  template: `<span data-id="heading-probe">{{ headingId }}</span>`,
})
class HeadingProbe {
  protected readonly headingId = inject(HILOS_PAGE_HEADING_ID)
}

/** Real provider and look-only consumers; no parent computes the verdict. */
@Component({
  selector: 'test-admin-view-mode-probe',
  template: `
    <span data-id="view-mode-probe">{{ viewMode() }}</span>
    <span data-id="look-only-probe">{{ lookOnly.locked() }}</span>
    <span data-id="look-only-described">{{ lookOnly.describedBy() }}</span>
  `,
})
class ViewModeProbe {
  protected readonly viewMode = inject(HILOS_ADMIN_VIEW_MODE)
  protected readonly lookOnly = injectLookOnly()
}

/** Native caller markup; omitted label bindings keep Angular input defaults. */
function callerTemplate(labels: string): string {
  const props = `
    [page]="state.page"
    [childParams]="state.childParams"
    [headKind]="state.headKind"
    [headAction]="state.headAction"
    [headTitleClass]="state.headTitleClass"
    ${labels}
  `
  return `
    @if (state.body || state.headContent || state.contextProbe || state.table) {
      <hilos-admin-page ${props}>
        @if (state.headContent?.visual; as part) {
          <ng-template #headVisual>
            <span [attr.data-id]="part.dataId">{{ part.text }}</span>
          </ng-template>
        }
        @if (state.headContent?.meta; as part) {
          <ng-template #headMeta>
            <span [attr.data-id]="part.dataId">{{ part.text }}</span>
          </ng-template>
        }
        @if (state.headContent?.badges; as part) {
          <ng-template #headBadges>
            <span [attr.data-id]="part.dataId">{{ part.text }}</span>
          </ng-template>
        }
        @if (state.headContent?.description; as part) {
          <ng-template #headDescription>
            <span [attr.data-id]="part.dataId">{{ part.text }}</span>
          </ng-template>
        }
        @if (state.headContent?.skeletonVisual; as part) {
          <ng-template #headSkeletonVisual>
            <span [attr.data-id]="part.dataId">{{ part.text }}</span>
          </ng-template>
        }
        @if (state.body; as body) {
          <p [attr.data-id]="body.dataId">{{ body.text }}</p>
        } @else if (state.contextProbe === 'heading') {
          <test-admin-heading-probe />
        } @else if (state.contextProbe === 'view-mode') {
          <test-admin-view-mode-probe />
        } @else if (state.table; as table) {
          <hilos-viewport-table [controller]="table" />
        }
      </hilos-admin-page>
    } @else {
      <hilos-admin-page ${props} />
    }
  `
}

/** Conditional TemplateRefs are real projected content, including withdrawal. */
@Component({
  selector: 'test-admin-page-host',
  imports: [HilosAdminPage, HilosViewportTable, HeadingProbe, ViewModeProbe],
  template: `
    @if (input(); as state) {
      @if (
        state.breadcrumbLabel === undefined && state.tabsLabel === undefined
      ) {
        ${callerTemplate('')}
      } @else if (state.breadcrumbLabel === undefined) {
        ${callerTemplate('[tabsLabel]="state.tabsLabel"')}
      } @else if (state.tabsLabel === undefined) {
        ${callerTemplate('[breadcrumbLabel]="state.breadcrumbLabel"')}
      } @else {
        ${callerTemplate('[breadcrumbLabel]="state.breadcrumbLabel" [tabsLabel]="state.tabsLabel"')}
      }
    }
  `,
})
class AdminPageHost {
  readonly input = signal<AdminPageInput | null>(null)
}

/** Set the host's signal before Angular's first detection pass. */
export async function mountAdminPage(
  input: AdminPageInput,
): Promise<AdminPageView> {
  TestBed.configureTestingModule({
    providers: [
      { provide: HILOS_ROUTER, useValue: input.router },
      ...(input.deletion === undefined
        ? []
        : [
            { provide: MODALS_MOUNTED, useValue: input.deletion.modalsMounted },
          ]),
      ...(input.deletion?.retain === undefined
        ? []
        : [
            {
              provide: HILOS_PAGE_ERROR_BOUNDARY,
              useValue: input.deletion.retain,
            },
          ]),
    ],
  })
  const host = document.body.appendChild(document.createElement('div'))
  let fixture: ComponentFixture<AdminPageHost | HeldAdminPageHost> | undefined
  function destroy(): void {
    try {
      fixture?.destroy()
    } finally {
      TestBed.resetTestingModule()
      host.remove()
    }
  }
  try {
    fixture = TestBed.createComponent<AdminPageHost | HeldAdminPageHost>(
      input.deletion === undefined ? AdminPageHost : HeldAdminPageHost,
    )
    const nativeDestroy = fixture.destroy.bind(fixture)
    let destroyed = false
    fixture.destroy = () => {
      if (destroyed) return
      destroyed = true
      nativeDestroy()
    }
    fixture.componentInstance.input.set(input)
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.detectChanges()
    return {
      root: host,
      async update(next) {
        fixture!.componentInstance.input.set(next)
        fixture!.detectChanges()
      },
      async flush(change) {
        change()
        fixture!.detectChanges()
      },
      async unmount() {
        destroy()
      },
    }
  } catch (error) {
    destroy()
    throw error
  }
}
