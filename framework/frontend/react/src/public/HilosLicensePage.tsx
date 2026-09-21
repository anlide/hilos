// HilosLicensePage — the tier-2 public /license page: the project's own license
// prose (the children) and, under it, the inventory of everything the build
// actually stands on. The list is not typed by hand and not fetched: it is the
// build-time snapshot generated from the project's own two lockfiles
// (framework/frontend/scripts/generate-license-inventory.mjs), handed over in one
// prop, so the page renders whole on a machine with no browser and no server
// behind it (build-and-docker.md, the SSG section).
//
// The page holds no collection logic of its own — filtering, the option lists,
// the label and the CSV rendering are @hilos/core, so the three view layers
// cannot drift apart. It carries no `title` prop either: the heading moves with
// the frame, and the project's file holds prose alone.
//
// The clipboard is read on the CLICK and never during render: this page is
// executed at build time by a server renderer where `navigator` does not exist at
// all, so a render-time probe would decide the static file's contents by the
// absence of a browser on the build machine, and the button would appear only
// after the SPA mounts. The two export buttons are therefore always rendered and
// never disabled — on an empty result they hand over the header row alone.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import {
  type HilosLicenseEntry,
  type HilosLicenseInventory,
  copyToClipboard,
  downloadTextFile,
  filterLicenseEntries,
  licenseCsvFileName,
  licenseFilterOptions,
  licenseLanguageLabel,
  renderLicenseCsv,
} from '@hilos/core'

import { HilosLongText } from '../HilosLongText.js'
import { HilosModal } from '../HilosModal.js'
import { HilosStaticPage } from '../HilosStaticPage.js'

/** The type the export is offered under. */
const DOWNLOAD_MIME_TYPE = 'text/csv;charset=utf-8'

/** Props for {@link HilosLicensePage}. */
export interface HilosLicensePageProps {
  /** The build-time snapshot of everything this build stands on. */
  inventory: HilosLicenseInventory
  /** The project's own license prose, above the inventory. */
  children?: ReactNode
}

/**
 * The /license page: the project's prose, and under it the build's inventory
 * with a search, two filters, a package's own license text, and both exports.
 *
 * @param props The build-time snapshot and the project's prose.
 */
export function HilosLicensePage({
  inventory,
  children,
}: HilosLicensePageProps) {
  const [search, setSearch] = useState('')
  const [license, setLicense] = useState('')
  const [language, setLanguage] = useState('')
  const [openEntry, setOpenEntry] = useState<HilosLicenseEntry | null>(null)
  const [copyStatus, setCopyStatus] = useState('')

  // Both selects offer what the snapshot actually holds, never a fixed enum: a
  // hard-coded list would silently hide a license nobody expected, which is the
  // one thing this page exists to surface.
  const options = useMemo(
    () => licenseFilterOptions(inventory.entries),
    [inventory],
  )

  const visible = useMemo(
    () =>
      filterLicenseEntries(inventory.entries, { search, license, language }),
    [inventory, search, license, language],
  )

  // The status speaks about the list that was handed over, so it goes as soon as
  // the list underneath it changes — the same reason a reopened modal does not
  // keep reporting the copy of its last visit.
  useEffect(() => {
    setCopyStatus('')
  }, [visible])

  async function onCopy(): Promise<void> {
    const copied = await copyToClipboard(renderLicenseCsv(visible))
    setCopyStatus(
      copied ? 'Copied' : 'The browser did not allow copying — use Download',
    )
  }

  function onDownload(): void {
    downloadTextFile(
      licenseCsvFileName(inventory.project),
      renderLicenseCsv(visible),
      DOWNLOAD_MIME_TYPE,
    )
  }

  return (
    <HilosStaticPage title="License">
      {children}

      <h2 className="h6 text-uppercase text-body-secondary mb-2 mt-4">
        What this build stands on
      </h2>

      <div className="d-flex flex-wrap gap-2 mb-3">
        <div className="input-group input-group-sm w-auto flex-grow-1 flex-md-grow-0">
          <span className="input-group-text">
            <i className="bi bi-search" aria-hidden="true" />
          </span>
          {/* The placeholder doubles as the field's accessible name, as it does
              on the table bar: the field carries no visible label, and two
              different strings would name it twice. The two selects are named by
              their own "all" option for the same reason. */}
          <input
            type="search"
            className="form-control"
            placeholder="Search a package or a license"
            aria-label="Search a package or a license"
            data-id="license-search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        <select
          className="form-select form-select-sm w-auto"
          aria-label="All licenses"
          data-id="license-filter-license"
          value={license}
          onChange={(event) => setLicense(event.target.value)}
        >
          <option value="">All licenses</option>
          {options.licenses.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
        <select
          className="form-select form-select-sm w-auto"
          aria-label="All languages"
          data-id="license-filter-language"
          value={language}
          onChange={(event) => setLanguage(event.target.value)}
        >
          <option value="">All languages</option>
          {options.languages.map((option) => (
            <option key={option} value={option}>
              {licenseLanguageLabel(option)}
            </option>
          ))}
        </select>
      </div>

      <div data-id="license-inventory">
        {visible.map((entry) => (
          <button
            key={`${entry.language}/${entry.name}/${entry.version}`}
            type="button"
            className="d-flex align-items-center gap-2 py-1 small w-100 text-start border-0 border-bottom bg-transparent"
            data-id="license-row"
            onClick={() => setOpenEntry(entry)}
          >
            <span className="flex-grow-1">
              {entry.name}{' '}
              <span className="text-body-secondary">{entry.version}</span>
            </span>
            <span className="badge text-bg-light border">
              {licenseLanguageLabel(entry.language)}
            </span>
            <span className="badge text-bg-light border">{entry.license}</span>
          </button>
        ))}
        {visible.length === 0 ? (
          <p className="small text-body-secondary mb-0" data-id="license-empty">
            Nothing in this build matches these filters
          </p>
        ) : null}
      </div>

      <div className="d-flex flex-wrap align-items-center gap-2 mt-3">
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          data-id="license-copy"
          onClick={() => void onCopy()}
        >
          <i className="bi bi-clipboard me-1" aria-hidden="true" />
          Copy the list
        </button>
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          data-id="license-download"
          onClick={onDownload}
        >
          <i className="bi bi-download me-1" aria-hidden="true" />
          Download as a file
        </button>
        {/* Rendered even while empty: a live region has to be in the document
            before its text changes, or the change is never announced. */}
        <span
          className="small text-body-secondary"
          role="status"
          aria-live="polite"
          data-id="license-copy-status"
        >
          {copyStatus}
        </span>
      </div>

      <HilosModal
        open={openEntry !== null}
        title={
          openEntry === null
            ? ''
            : `${openEntry.name} ${openEntry.version} · ${openEntry.license}`
        }
        initialFocus="dialog"
        size="wide"
        onClose={() => setOpenEntry(null)}
        actions={({ requestClose }) => (
          <>
            {openEntry !== null && openEntry.repository !== null ? (
              <a
                href={openEntry.repository}
                className="btn btn-outline-secondary"
                target="_blank"
                rel="noopener noreferrer"
                data-id="license-repository"
              >
                <i
                  className="bi bi-box-arrow-up-right me-1"
                  aria-hidden="true"
                />
                Repository
              </a>
            ) : null}
            <button
              type="button"
              className="btn btn-secondary"
              onClick={requestClose}
            >
              Close
            </button>
          </>
        )}
      >
        {openEntry !== null && openEntry.licenseText !== null ? (
          <HilosLongText
            kind="output"
            text={openEntry.licenseText}
            dataId="license-text"
          />
        ) : (
          <p className="small mb-0" data-id="license-text-missing">
            This package ships no license file; the type above comes from its
            manifest
          </p>
        )}
      </HilosModal>
    </HilosStaticPage>
  )
}
