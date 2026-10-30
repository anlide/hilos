// The Angular view for the build's dependency inventory. Core owns the local
// table, filtering, package selection and export lifecycle; this component
// adapts its signals and renders the shared table and modal.
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLicenseDependencies,
  licenseLanguageLabel,
  subscribeSignal,
  type HilosLicenseDependenciesController,
  type HilosLicenseEntry,
  type HilosLicenseInventory,
  type HilosStaticPageNavigation,
  type Unsubscribe,
} from '@hilos/core/base'

import { HilosLongText } from '../HilosLongText.js'
import { HilosModal } from '../HilosModal.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosTableCell } from '../HilosTableCell.js'
import { HilosViewportTable } from '../HilosViewportTable.js'

/** The build's local dependency inventory and package license reader. */
@Component({
  selector: 'hilos-license-dependencies-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosLongText,
    HilosModal,
    HilosStaticPage,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-static-page
      title="License"
      icon="bi-journal-code"
      subtitle="The license of this project, and of everything it is built on."
      [navigation]="navigation()"
    >
      @if (dependencies(); as dependencies) {
        <hilos-viewport-table [controller]="dependencies.table">
          <ng-template hilosTableCell="name" let-row>
            <button
              type="button"
              class="btn btn-link link-body-emphasis p-0 text-start"
              data-id="license-package-open"
              (click)="dependencies.open(row)"
            >
              {{ row.name }}
            </button>
            <span class="text-body-secondary ms-1">{{ row.version }}</span>
          </ng-template>
          <ng-template hilosTableCell="language" let-row>
            {{ languageLabel(row.language) }}
          </ng-template>
          <ng-template hilosTableCell="license" let-row>
            {{ row.license }}
          </ng-template>
        </hilos-viewport-table>
      }

      <div class="hilos-stack small text-body-secondary mt-2">
        <span class="invisible" aria-hidden="true">Copied</span>
        <span class="invisible" aria-hidden="true">
          The browser did not allow copying — use Download
        </span>
        <span role="status" aria-live="polite" data-id="license-copy-status">{{
          copyStatus()
        }}</span>
      </div>

      <hilos-modal
        [open]="entry() !== null"
        (openChange)="onModalOpen($event)"
        [title]="modalTitle()"
        initialFocus="dialog"
        size="wide"
      >
        @if (entry(); as selectedEntry) {
          @if (selectedEntry.licenseText !== null) {
            <hilos-long-text
              kind="output"
              [text]="selectedEntry.licenseText"
              dataId="license-text"
            />
          } @else {
            <p class="small mb-0" data-id="license-text-missing">
              This package ships no license file; the type above comes from its
              manifest
            </p>
          }
        }

        <ng-template #modalActions let-requestClose="requestClose">
          @if (entry(); as selectedEntry) {
            @if (selectedEntry.repository !== null) {
              <a
                [href]="selectedEntry.repository"
                class="btn"
                [class.btn-primary]="selectedEntry.licenseText === null"
                [class.btn-outline-secondary]="
                  selectedEntry.licenseText !== null
                "
                target="_blank"
                rel="noopener noreferrer"
                data-id="license-repository"
              >
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                Repository
              </a>
            }
          }
          <button
            type="button"
            class="btn btn-secondary"
            (click)="requestClose()"
          >
            Close
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-static-page>
  `,
})
export class HilosLicenseDependenciesPage {
  /** The complete build-time dependency snapshot. */
  readonly inventory = input.required<HilosLicenseInventory>()
  /** Optional navigation across sibling public pages. */
  readonly navigation = input<HilosStaticPageNavigation>()

  protected readonly dependencies =
    signal<HilosLicenseDependenciesController | null>(null)
  protected readonly entry = signal<HilosLicenseEntry | null>(null)
  protected readonly copyStatus = signal('')
  protected readonly languageLabel = licenseLanguageLabel
  protected readonly modalTitle = computed(() => {
    const entry = this.entry()

    return entry === null
      ? ''
      : `${entry.name} ${entry.version} · ${entry.license}`
  })

  private readonly destroyRef = inject(DestroyRef)
  private controller: HilosLicenseDependenciesController | null = null
  private readonly unsubscribe: Unsubscribe[] = []

  constructor() {
    // The factory's first complete window is synchronous. Keep the same
    // controller when Angular receives a replacement inventory snapshot.
    effect(() => {
      const inventory = this.inventory()
      let controller = this.controller

      if (controller === null) {
        controller = createHilosLicenseDependencies(inventory)
        this.controller = controller
        this.dependencies.set(controller)
        this.entry.set(controller.entry.get())
        this.copyStatus.set(controller.copyStatus.get())
        this.unsubscribe.push(
          subscribeSignal(controller.entry, (entry) => this.entry.set(entry)),
          subscribeSignal(controller.copyStatus, (status) =>
            this.copyStatus.set(status),
          ),
        )

        return
      }

      controller.setInventory(inventory)
    })

    this.destroyRef.onDestroy(() => {
      for (const unsubscribe of this.unsubscribe) unsubscribe()
      this.controller?.dispose()
      this.controller = null
    })
  }

  /** Close the core-owned package selection when the modal requests it. */
  protected onModalOpen(open: boolean): void {
    if (!open) this.controller?.close()
  }
}
