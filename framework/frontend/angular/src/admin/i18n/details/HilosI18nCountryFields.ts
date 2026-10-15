// HilosI18nCountryFields — the fields of one country, shared by the creation
// form and the edit/view window: the code, the currency symbol and code, and the
// default locale. Which of them can be changed is the mode's: the code only
// while adding, the currency while adding or editing, the default locale only
// while editing — from the options and the hint the core window resolves over
// the country's own locales, switched off ones included. A new country has no
// locales, so its select holds "Not chosen" alone. In view every field is read,
// described by the window's plate. The parent owns the values and hands every
// change to its core flow. The edit hint holds the room of its longest variant
// before it arrives, in either theme and at any width. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core'
import {
  HILOS_I18N_COUNTRY_ADD_COPY,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
} from '@hilos/core'
import type { HilosI18nCountryLocaleOption } from '@hilos/core'

/** The country fields of the creation form and of the edit/view window. */
@Component({
  selector: 'hilos-i18n-country-fields',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'd-block' },
  template: `
    <div class="row g-2">
      <div class="col-12 col-sm-4">
        <label
          class="form-label small fw-semibold"
          [for]="idPrefix() + '-code'"
        >
          {{ copy.codeLabel }}
        </label>
        <input
          [id]="idPrefix() + '-code'"
          class="form-control form-control-sm"
          [value]="code()"
          [placeholder]="copy.codePlaceholder"
          maxlength="2"
          [attr.aria-describedby]="describe('-code-hint')"
          [attr.data-id]="idPrefix() + '-code'"
          [disabled]="mode() !== 'add'"
          [attr.data-autofocus]="mode() === 'add' ? '' : null"
          (input)="changeCode($event)"
        />
        @if (mode() !== 'view') {
          <div
            [id]="idPrefix() + '-code-hint'"
            [attr.data-id]="idPrefix() + '-code-hint'"
            class="form-text"
          >
            @if (mode() === 'edit') {
              <i class="bi bi-database-check me-1" aria-hidden="true"></i>
            }
            {{ mode() === 'add' ? copy.codeHint : windowCopy.codeHint }}
          </div>
        }
      </div>
      <div class="col-12 col-sm-4">
        <label
          class="form-label small fw-semibold"
          [for]="idPrefix() + '-currency-symbol'"
        >
          {{ copy.currencySymbolLabel }}
        </label>
        <input
          [id]="idPrefix() + '-currency-symbol'"
          class="form-control form-control-sm"
          [value]="currencySymbol()"
          [placeholder]="copy.currencySymbolPlaceholder"
          maxlength="8"
          [attr.aria-describedby]="describe('-currency-symbol-hint')"
          [attr.data-id]="idPrefix() + '-currency-symbol'"
          [disabled]="mode() === 'view'"
          [attr.data-autofocus]="mode() === 'edit' ? '' : null"
          (input)="changeCurrencySymbol($event)"
        />
        @if (mode() !== 'view') {
          <div [id]="idPrefix() + '-currency-symbol-hint'" class="form-text">
            {{ copy.currencySymbolHint }}
          </div>
        }
      </div>
      <div class="col-12 col-sm-4">
        <label
          class="form-label small fw-semibold"
          [for]="idPrefix() + '-currency-code'"
        >
          {{ copy.currencyCodeLabel }}
        </label>
        <input
          [id]="idPrefix() + '-currency-code'"
          class="form-control form-control-sm"
          [value]="currencyCode()"
          [placeholder]="copy.currencyCodePlaceholder"
          maxlength="3"
          [attr.aria-describedby]="describe('-currency-code-hint')"
          [attr.data-id]="idPrefix() + '-currency-code'"
          [disabled]="mode() === 'view'"
          (input)="changeCurrencyCode($event)"
        />
        @if (mode() !== 'view') {
          <div [id]="idPrefix() + '-currency-code-hint'" class="form-text">
            {{ copy.currencyCodeHint }}
          </div>
        }
      </div>
      <div class="col-12">
        <label
          class="form-label small fw-semibold"
          [for]="idPrefix() + '-default-locale'"
        >
          {{ copy.defaultLocaleLabel }}
        </label>
        <select
          [id]="idPrefix() + '-default-locale'"
          class="form-select form-select-sm"
          [disabled]="mode() !== 'edit'"
          [attr.aria-describedby]="describe('-default-locale-hint')"
          [attr.data-id]="idPrefix() + '-default-locale'"
          (change)="changeDefaultLocale($event)"
        >
          @if (mode() === 'add') {
            <option value="" selected>{{ copy.defaultLocaleChoice }}</option>
          } @else {
            @for (option of localeOptions(); track option.value) {
              <option
                [value]="option.value"
                [selected]="
                  option.value ===
                  (defaultLocaleId() === null ? '' : '' + defaultLocaleId())
                "
              >
                {{ option.label }}
              </option>
            }
          }
        </select>
        @if (mode() === 'add') {
          <div
            [id]="idPrefix() + '-default-locale-hint'"
            class="form-text"
            [attr.data-id]="idPrefix() + '-default-locale-hint'"
          >
            <i class="bi bi-database-check me-1" aria-hidden="true"></i>
            {{ copy.defaultLocaleHint }}
          </div>
        } @else if (mode() === 'edit') {
          <div class="form-text hilos-stack">
            <div class="hilos-stack invisible" aria-hidden="true" inert>
              @for (hint of hints; track hint) {
                <span>{{ hint }}</span>
              }
            </div>
            <div
              [id]="idPrefix() + '-default-locale-hint'"
              [attr.data-id]="idPrefix() + '-default-locale-hint'"
            >
              {{ localeHint() }}
            </div>
          </div>
        }
      </div>
    </div>
  `,
})
export class HilosI18nCountryFields {
  /** The prefix of every id and data-id of the fields. */
  readonly idPrefix = input.required<string>()
  /** Which fields can change: the code only in add, none in view. */
  readonly mode = input.required<'add' | 'edit' | 'view'>()
  /** The country code. */
  readonly code = input.required<string>()
  /** The currency symbol a person sees. */
  readonly currencySymbol = input.required<string>()
  /** The ISO 4217 currency code. */
  readonly currencyCode = input.required<string>()
  /** The chosen default locale; null while none is chosen. */
  readonly defaultLocaleId = input<number | null>(null)
  /** The default-locale options the core window resolves. */
  readonly localeOptions = input<readonly HilosI18nCountryLocaleOption[]>([])
  /** The default-locale hint the core window resolves. */
  readonly localeHint = input('')
  /** The id describing every field in view: the window's plate. */
  readonly describedBy = input<string | null>(null)
  /** The code typed in the creation form. */
  readonly codeChange = output<string>()
  /** The currency symbol typed. */
  readonly currencySymbolChange = output<string>()
  /** The currency code typed. */
  readonly currencyCodeChange = output<string>()
  /** The default locale chosen; null for "Not chosen". */
  readonly defaultLocaleIdChange = output<string | null>()

  protected readonly copy = HILOS_I18N_COUNTRY_ADD_COPY
  protected readonly windowCopy = HILOS_I18N_COUNTRY_WINDOW_COPY
  protected readonly hints = Object.values(HILOS_I18N_COUNTRY_WINDOW_COPY.hints)

  /**
   * The id describing a field: the plate in view, the field's own hint else.
   *
   * @param hint The suffix of the field's own hint id.
   */
  protected describe(hint: string): string | null {
    return this.mode() === 'view' ? this.describedBy() : this.idPrefix() + hint
  }

  protected changeCode(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.codeChange.emit(event.target.value)
    }
  }

  protected changeCurrencySymbol(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.currencySymbolChange.emit(event.target.value)
    }
  }

  protected changeCurrencyCode(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.currencyCodeChange.emit(event.target.value)
    }
  }

  protected changeDefaultLocale(event: Event): void {
    if (event.target instanceof HTMLSelectElement) {
      this.defaultLocaleIdChange.emit(event.target.value || null)
    }
  }
}
