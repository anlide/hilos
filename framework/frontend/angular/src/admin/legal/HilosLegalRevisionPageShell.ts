import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  Injector,
  input,
  signal,
  viewChild,
  type TemplateRef,
} from '@angular/core'
import {
  createHilosLegalRevision,
  LEGAL_CATALOG_REFUSAL_SECTION,
  type HilosLegalAdminRevision,
} from '@hilos/core/admin/legal'
import {
  bindPageInstanceName,
  computedSignal,
  hilosLegalDocumentLabel,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosSkeleton,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** The revision's shared entity head and the current tab's body template. */
@Component({
  selector: 'hilos-legal-revision-page-shell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosSkeleton, NgTemplateOutlet],
  template: `
    <hilos-admin-page
      [page]="page()"
      headKind="entity"
      tabsLabel="Revision pages"
    >
      <ng-template #headVisual>
        <span
          class="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
          aria-hidden="true"
        >
          <i class="bi bi-file-earmark-text"></i>
        </span>
      </ng-template>
      <ng-template #headBadges>
        @if (details(); as data) {
          @if (data.current) {
            <span class="badge text-bg-primary" data-id="legal-revision-current"
              >Current</span
            >
          }
          @if (data.declared && data.revision) {
            <span
              class="badge text-bg-secondary"
              data-id="legal-revision-significance"
              >{{ data.revision.significance }}</span
            >
          }
          @if (!data.declared) {
            <span class="badge text-bg-warning">Not in code</span>
          }
        }
      </ng-template>
      <ng-template #headDescription>
        @if (details(); as data) {
          <p class="text-body-secondary mb-0" data-id="legal-revision-metadata">
            {{ documentLabel(data.document) }}
            @if (data.declared && data.revision) {
              · Published {{ data.revision.publishedOn }} · Effective
              {{ data.revision.effectiveOn }} · Set
              {{ data.revision.setVersion }}
            }
          </p>
        }
      </ng-template>
      <ng-template #pageBody>
        @if (refusal() !== null) {
          <div
            class="alert alert-danger text-break"
            role="alert"
            data-id="legal-catalog-refusal"
          >
            <h2 class="h6">The legal document catalog could not be loaded</h2>
            <p class="mb-0">{{ refusal() }}</p>
          </div>
        } @else if (details(); as data) {
          @if (!data.declared) {
            <div
              class="alert alert-warning"
              data-id="legal-revision-undeclared"
            >
              This revision is no longer in code. Its text and comparison are
              unavailable.
            </div>
          }
          @if (bodyInjector(); as injector) {
            <ng-container
              [ngTemplateOutlet]="body() ?? null"
              [ngTemplateOutletInjector]="injector"
            />
          }
        } @else {
          <hilos-skeleton />
        }
      </ng-template>
    </hilos-admin-page>
  `,
})
export class HilosLegalRevisionPageShell {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()
  /** The tab whose own answer supplies the revision. */
  readonly page = input.required<string>()
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly body = contentChild<TemplateRef<unknown>>('pageBody')
  // Instantiate the caller's body under the real admin heading and view mode.
  protected readonly bodyInjector = viewChild(HilosAdminPage, {
    read: Injector,
  })
  protected readonly details = signal<
    HilosLegalAdminRevision | null | undefined
  >(undefined)
  protected readonly refusal = signal<string | null>(null)
  private readonly binding = computed(() => {
    const context = this.context()
    return {
      revision: createHilosLegalRevision(context),
      refusal: context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
    }
  })

  constructor() {
    effect((onCleanup) => {
      const { scopes } = this.context()
      const page = this.page()
      const binding = this.binding()
      const refusal = computedSignal(() => {
        if (scopes.page()?.key !== page) return null
        const value = binding.refusal.get()
        return typeof value === 'string' ? value : null
      })
      const currentRevision = computedSignal(() =>
        scopes.page()?.key === page && refusal.get() === null
          ? binding.revision.get()
          : undefined,
      )
      const unbindDocument = bindPageInstanceName(
        scopes,
        HilosPages.LEGAL_DOCUMENT,
        computedSignal(() => {
          const current = currentRevision.get()
          return current ? hilosLegalDocumentLabel(current.document) : undefined
        }),
      )
      const unbindRevision = bindPageInstanceName(
        scopes,
        HilosPages.LEGAL_REVISION,
        computedSignal(() => {
          const current = currentRevision.get()
          if (!current) return undefined
          return current.declared && current.revision
            ? 'Revision of ' + current.revision.publishedOn
            : 'Revision ' + current.revisionId
        }),
      )
      const off = [
        mirrorHilosSignal(currentRevision, this.details),
        mirrorHilosSignal(refusal, this.refusal),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        unbindDocument()
        unbindRevision()
      })
    })
  }
}
