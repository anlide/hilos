import {
  HILOS_I18N_COUNTRY_ADD_COPY,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  type HilosI18nCountryLocaleOption,
} from '@hilos/core'

/** Fields shared by country creation and the core's edit/view window. */
export function HilosI18nCountryFields({
  idPrefix,
  mode,
  code,
  currencySymbol,
  currencyCode,
  defaultLocaleId,
  localeOptions = [],
  localeHint,
  describedBy,
  busy = false,
  onCode,
  onCurrencySymbol,
  onCurrencyCode,
  onDefaultLocale,
}: {
  idPrefix: string
  mode: 'add' | 'edit' | 'view'
  code: string
  currencySymbol: string
  currencyCode: string
  defaultLocaleId?: number | null
  localeOptions?: readonly HilosI18nCountryLocaleOption[]
  localeHint?: string
  describedBy?: string
  busy?: boolean
  onCode?: (value: string) => void
  onCurrencySymbol: (value: string) => void
  onCurrencyCode: (value: string) => void
  onDefaultLocale?: (value: string | null) => void
}) {
  const copy = HILOS_I18N_COUNTRY_ADD_COPY,
    windowCopy = HILOS_I18N_COUNTRY_WINDOW_COPY
  return (
    <div className="row g-2">
      <div className="col-12 col-sm-4">
        <label
          className="form-label small fw-semibold"
          htmlFor={`${idPrefix}-code`}
        >
          {copy.codeLabel}
        </label>
        <input
          id={`${idPrefix}-code`}
          className="form-control form-control-sm"
          value={code}
          placeholder={copy.codePlaceholder}
          maxLength={2}
          aria-describedby={
            mode === 'view' ? describedBy : `${idPrefix}-code-hint`
          }
          data-id={`${idPrefix}-code`}
          disabled={mode !== 'add' || busy}
          data-autofocus={mode === 'add' ? '' : undefined}
          onChange={(event) => onCode?.(event.target.value)}
        />
        {mode !== 'view' && (
          <div
            id={`${idPrefix}-code-hint`}
            data-id={`${idPrefix}-code-hint`}
            className="form-text"
          >
            {mode === 'edit' && (
              <i className="bi bi-database-check me-1" aria-hidden="true" />
            )}
            {mode === 'add' ? copy.codeHint : windowCopy.codeHint}
          </div>
        )}
      </div>
      <div className="col-12 col-sm-4">
        <label
          className="form-label small fw-semibold"
          htmlFor={`${idPrefix}-currency-symbol`}
        >
          {copy.currencySymbolLabel}
        </label>
        <input
          id={`${idPrefix}-currency-symbol`}
          className="form-control form-control-sm"
          value={currencySymbol}
          placeholder={copy.currencySymbolPlaceholder}
          maxLength={8}
          aria-describedby={
            mode === 'view' ? describedBy : `${idPrefix}-currency-symbol-hint`
          }
          data-id={`${idPrefix}-currency-symbol`}
          disabled={mode === 'view' || busy}
          data-autofocus={mode === 'edit' ? '' : undefined}
          onChange={(event) => onCurrencySymbol(event.target.value)}
        />
        {mode !== 'view' && (
          <div id={`${idPrefix}-currency-symbol-hint`} className="form-text">
            {copy.currencySymbolHint}
          </div>
        )}
      </div>
      <div className="col-12 col-sm-4">
        <label
          className="form-label small fw-semibold"
          htmlFor={`${idPrefix}-currency-code`}
        >
          {copy.currencyCodeLabel}
        </label>
        <input
          id={`${idPrefix}-currency-code`}
          className="form-control form-control-sm"
          value={currencyCode}
          placeholder={copy.currencyCodePlaceholder}
          maxLength={3}
          aria-describedby={
            mode === 'view' ? describedBy : `${idPrefix}-currency-code-hint`
          }
          data-id={`${idPrefix}-currency-code`}
          disabled={mode === 'view' || busy}
          onChange={(event) => onCurrencyCode(event.target.value)}
        />
        {mode !== 'view' && (
          <div id={`${idPrefix}-currency-code-hint`} className="form-text">
            {copy.currencyCodeHint}
          </div>
        )}
      </div>
      <div className="col-12">
        <label
          className="form-label small fw-semibold"
          htmlFor={`${idPrefix}-default-locale`}
        >
          {copy.defaultLocaleLabel}
        </label>
        <select
          id={`${idPrefix}-default-locale`}
          className="form-select form-select-sm"
          disabled={mode !== 'edit' || busy}
          value={defaultLocaleId == null ? '' : String(defaultLocaleId)}
          aria-describedby={
            mode === 'view' ? describedBy : `${idPrefix}-default-locale-hint`
          }
          data-id={`${idPrefix}-default-locale`}
          onChange={(event) => onDefaultLocale?.(event.target.value || null)}
        >
          {mode === 'add' ? (
            <option value="">{copy.defaultLocaleChoice}</option>
          ) : (
            localeOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))
          )}
        </select>
        {mode === 'add' ? (
          <div
            id={`${idPrefix}-default-locale-hint`}
            className="form-text"
            data-id={`${idPrefix}-default-locale-hint`}
          >
            <i className="bi bi-database-check me-1" aria-hidden="true" />
            {copy.defaultLocaleHint}
          </div>
        ) : (
          mode === 'edit' && (
            <div className="form-text hilos-stack">
              <div className="hilos-stack invisible" aria-hidden="true" inert>
                {Object.values(windowCopy.hints).map((hint) => (
                  <span key={hint}>{hint}</span>
                ))}
              </div>
              <div
                id={`${idPrefix}-default-locale-hint`}
                data-id={`${idPrefix}-default-locale-hint`}
              >
                {localeHint}
              </div>
            </div>
          )
        )}
      </div>
    </div>
  )
}
