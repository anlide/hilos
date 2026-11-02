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
  createHilosLegalDocument,
  LEGAL_CATALOG_REFUSAL_SECTION,
  type HilosLegalAdminDocument,
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

/** The document's shared entity head and the current tab's body template. */
@Component({
  selector: 'hilos-legal-document-page-shell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosSkeleton, NgTemplateOutlet],
  template: `
    <hilos-admin-page
      [page]="page()"
      headKind="entity"
      tabsLabel="Document pages"
    >
      <ng-template #headVisual>
        <span
          class="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
          aria-hidden="true"
        >
          <i class="bi bi-file-earmark-text"></i>
        </span>
      </ng-template>
      <ng-template #headMeta>
        @if (details(); as document) {
          @if (refusal() === null) {
            <code data-id="legal-document-key">{{ document.document }}</code>
          }
        }
      </ng-template>
      <ng-template #headBadges>
        @if (details(); as document) {
          @if (refusal() === null) {
            @if (document.set; as set) {
              <span
                class="badge bg-body-tertiary text-body-emphasis border"
                data-id="legal-document-set-version"
              >
                Set {{ set.version }}
              </span>
            }
            @if (!document.declared) {
              <span class="badge text-bg-warning">Not in code</span>
            }
          }
        }
      </ng-template>
      <ng-template #headDescription>
        @if (details()?.revision; as revision) {
          @if (refusal() === null) {
            <p
              class="text-body-secondary mb-0"
              data-id="legal-document-revision"
            >
              {{ revision.publishedOn }} · {{ revision.significance }} ·
              Effective {{ revision.effectiveOn }}
            </p>
          }
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
        } @else if (details()) {
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
export class HilosLegalDocumentPageShell {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()
  /** The tab whose own answer supplies the document. */
  readonly page = input.required<string>()
  protected readonly body = contentChild<TemplateRef<unknown>>('pageBody')
  // Instantiate the caller's body under the real admin heading and view mode.
  protected readonly bodyInjector = viewChild(HilosAdminPage, {
    read: Injector,
  })
  protected readonly details = signal<
    HilosLegalAdminDocument | null | undefined
  >(undefined)
  protected readonly refusal = signal<string | null>(null)
  private readonly binding = computed(() => {
    const context = this.context()
    return {
      document: createHilosLegalDocument(context),
      refusal: context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
    }
  })

  constructor() {
    effect((onCleanup) => {
      const { scopes } = this.context()
      const page = this.page()
      const binding = this.binding()
      const refusal = computedSignal(() => {
        const value = binding.refusal.get()
        return typeof value === 'string' ? value : null
      })
      const unbindName = bindPageInstanceName(
        scopes,
        HilosPages.LEGAL_DOCUMENT,
        computedSignal(() => {
          if (scopes.page()?.key !== page || refusal.get() !== null)
            return undefined
          const current = binding.document.get()
          return current ? hilosLegalDocumentLabel(current.document) : undefined
        }),
      )
      const off = [
        mirrorHilosSignal(binding.document, this.details),
        mirrorHilosSignal(refusal, this.refusal),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        unbindName()
      })
    })
  }
}
