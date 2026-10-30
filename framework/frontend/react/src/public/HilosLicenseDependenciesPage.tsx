// HilosLicenseDependenciesPage renders one mount's local dependency inventory
// through the shared table and modal primitives. The core controller owns its
// filters, visible rows, package selection and export lifecycle.
import {
  useEffect,
  useInsertionEffect,
  useLayoutEffect,
  useRef,
  useState,
  useSyncExternalStore,
} from 'react'
import {
  createHilosLicenseDependencies,
  licenseLanguageLabel,
  type HilosLicenseDependenciesController,
  type HilosLicenseInventory,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import { HilosLongText } from '../HilosLongText.js'
import { HilosModal } from '../HilosModal.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosViewportTable } from '../HilosViewportTable.js'
import { useSignal } from '../useSignal.js'

/** Props for {@link HilosLicenseDependenciesPage}. */
export interface HilosLicenseDependenciesPageProps {
  /** The complete build-time dependency snapshot. */
  inventory: HilosLicenseInventory
  /** Optional navigation across sibling public pages. */
  navigation?: HilosStaticPageNavigation
}

/** A root's render environment never changes and needs no subscriptions. */
function subscribeToRenderEnvironment(): () => void {
  return () => {}
}

/**
 * Render the dependency snapshot through the framework's standard table and modal.
 *
 * @param props The inventory snapshot and optional public-page navigation.
 */
export function HilosLicenseDependenciesPage({
  inventory,
  navigation,
}: HilosLicenseDependenciesPageProps) {
  const client = useSyncExternalStore(
    subscribeToRenderEnvironment,
    () => true,
    () => false,
  )
  // SSR has the full snapshot without effects or browser APIs. In the browser,
  // layout setup publishes the resource before the table's first painted frame.
  const [dependencies, setDependencies] =
    useState<HilosLicenseDependenciesController | null>(() =>
      client ? null : createHilosLicenseDependencies(inventory),
    )
  const previousInventory = useRef(inventory)
  const owned = useRef<HilosLicenseDependenciesController | null>(null)
  const removed = useRef(false)
  const passiveConnected = useRef(false)

  useLayoutEffect(() => {
    if (previousInventory.current === inventory) return
    previousInventory.current = inventory
    dependencies?.setInventory(inventory)
  }, [dependencies, inventory])

  useLayoutEffect(() => {
    if (owned.current !== null) return
    owned.current = createHilosLicenseDependencies(previousInventory.current)
    setDependencies(owned.current)
  }, [])

  // Insertion cleanup runs on deletion, not hide or StrictMode effect replay.
  // A hidden Activity has already disconnected geometry and passive view
  // subscriptions, consuming our passive cleanup. Dispose it here, synchronously
  // invalidating pending copies. Visible/Suspense trees still have subscribers:
  // their terminal signal publications must wait for passive cleanup instead.
  useInsertionEffect(() => {
    removed.current = false
    return () => {
      removed.current = true
      if (passiveConnected.current) return
      owned.current?.dispose()
      owned.current = null
    }
  }, [])

  useEffect(() => {
    passiveConnected.current = true
    return () => {
      passiveConnected.current = false
      if (!removed.current) return
      owned.current?.dispose()
      owned.current = null
    }
  }, [])

  return dependencies === null ? null : (
    <DependenciesBody dependencies={dependencies} navigation={navigation} />
  )
}

/** Render only a live client resource or the complete server snapshot. */
function DependenciesBody({
  dependencies,
  navigation,
}: {
  dependencies: HilosLicenseDependenciesController
  navigation?: HilosStaticPageNavigation
}) {
  const entry = useSignal(dependencies.entry)
  const copyStatus = useSignal(dependencies.copyStatus)

  const modalTitle =
    entry === null ? '' : `${entry.name} ${entry.version} · ${entry.license}`

  return (
    <HilosStaticPage
      title="License"
      icon="bi-journal-code"
      subtitle="The license of this project, and of everything it is built on."
      navigation={navigation}
    >
      <HilosViewportTable
        controller={dependencies.table}
        cells={{
          name: (row) => (
            <>
              <button
                type="button"
                className="btn btn-link link-body-emphasis p-0 text-start"
                data-id="license-package-open"
                onClick={() => dependencies.open(row)}
              >
                {row.name}
              </button>
              <span className="text-body-secondary ms-1">{row.version}</span>
            </>
          ),
          language: (row) => licenseLanguageLabel(row.language),
          license: (row) => row.license,
        }}
      />

      <div className="hilos-stack small text-body-secondary mt-2">
        <span className="invisible" aria-hidden="true">
          Copied
        </span>
        <span className="invisible" aria-hidden="true">
          The browser did not allow copying — use Download
        </span>
        <span role="status" aria-live="polite" data-id="license-copy-status">
          {copyStatus}
        </span>
      </div>

      <HilosModal
        open={entry !== null}
        title={modalTitle}
        initialFocus="dialog"
        size="wide"
        onClose={dependencies.close}
        actions={({ requestClose }) => (
          <>
            {entry !== null && entry.repository !== null ? (
              <a
                href={entry.repository}
                className={
                  entry.licenseText === null
                    ? 'btn btn-primary'
                    : 'btn btn-outline-secondary'
                }
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
        {entry !== null && entry.licenseText !== null ? (
          <HilosLongText
            kind="output"
            text={entry.licenseText}
            dataId="license-text"
          />
        ) : entry !== null ? (
          <p className="small mb-0" data-id="license-text-missing">
            This package ships no license file; the type above comes from its
            manifest
          </p>
        ) : null}
      </HilosModal>
    </HilosStaticPage>
  )
}
