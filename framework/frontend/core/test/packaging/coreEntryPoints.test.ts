import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'
import * as analytics from '../../src/admin/analytics/index.js'
import * as analyticsOverview from '../../src/admin/analytics/hilosAnalyticsOverview.js'
import * as analyticsPeople from '../../src/admin/analytics/hilosAnalyticsPeople.js'
import * as analyticsPeriod from '../../src/admin/analytics/hilosAnalyticsPeriod.js'
import * as analyticsSessionList from '../../src/admin/analytics/hilosAnalyticsBrowserSessionList.js'
import * as appearance from '../../src/admin/appearance/index.js'
import * as backup from '../../src/admin/backup/index.js'
import * as changeLog from '../../src/admin/changeLog/index.js'
import * as communications from '../../src/admin/communications/index.js'
import * as channelSummary from '../../src/admin/communications/hilosCommunicationsChannel.js'
import * as letterFrame from '../../src/admin/communications/hilosCommunicationsChannelFrame.js'
import * as letterFrameEdit from '../../src/admin/communications/hilosCommunicationsChannelFrameEdit.js'
import * as daemon from '../../src/admin/daemon/index.js'
import * as i18n from '../../src/admin/i18n/index.js'
import * as legal from '../../src/admin/legal/index.js'
import * as logs from '../../src/admin/logs/index.js'
import * as maintenance from '../../src/admin/maintenance/index.js'
import * as moderation from '../../src/admin/moderation/index.js'
import * as moderationKind from '../../src/admin/moderation/hilosModerationKind.js'
import * as moderationRuleAdd from '../../src/admin/moderation/hilosModerationRuleAdd.js'
import * as moderationRules from '../../src/admin/moderation/hilosModerationRules.js'
import * as security from '../../src/admin/security/index.js'
import * as settings from '../../src/admin/settings/index.js'
import * as status from '../../src/admin/status/index.js'
import * as users from '../../src/admin/users/index.js'
import * as base from '../../src/base/index.js'
import * as restoreProgress from '../../src/connection/restoreProgress.js'
import * as tableFocusReturn from '../../src/dom/tableFocusReturn.js'
import * as core from '../../src/index.js'
import * as publicStatus from '../../src/status/index.js'
import * as timeZonePicker from '../../src/timeZone/timeZonePicker.js'
import * as profileNameState from '../../src/profile/profileNameState.js'
import * as profileRenameMemory from '../../src/profile/profileRenameMemory.js'

const packageRoot = fileURLToPath(new URL('../../', import.meta.url))
const sourceRoot = join(packageRoot, 'src')
const sections = {
  analytics,
  appearance,
  backup,
  'change-log': changeLog,
  communications,
  daemon,
  i18n,
  legal,
  logs,
  maintenance,
  moderation,
  security,
  settings,
  status,
  users,
}
const publicEntries: Record<string, Record<string, unknown>> = {
  './base': base,
  './status': publicStatus,
  ...Object.fromEntries(
    Object.entries(sections).map(([name, api]) => [`./admin/${name}`, api]),
  ),
}
const legacy = JSON.parse(
  readFileSync(new URL('./legacyCoreExports.json', import.meta.url), 'utf8'),
) as { value: string[]; type: string[] }
/**
 * The values the reader's time zone adds past the snapshot, listed by hand so
 * any other new export still fails the strict equality below.
 */
const timeZoneValues = [
  'bindTimeZoneState',
  'hilosBrowserTimeZone',
  'hilosOwnTimeZone',
  'hilosTimeZone',
  'readBrowserTimeZone',
  'resolveTimeZone',
]
/** The viewer's new selectors, shared by the compatibility root and logs entry. */
const logViewerValues = ['logViewerLatestBatchPath', 'logViewerStreamKeys']
/** The i18n overview APIs shared by the compatibility root and section entry. */
const i18nOverviewValues = [
  'I18N_OVERVIEW_DATA',
  'createHilosI18nOverview',
  'i18nOverviewSchema',
]
const i18nOverviewTypes = ['HilosI18nOverview', 'HilosI18nOverviewContext']
/** The page head's tab links resolver, shared by the compatibility root and base. */
const pageHeadValues = ['hilosTabLinks']
/** Public navigation types past the snapshot, shared by root and base. */
const pageHeadTypes = [
  'HilosPageHeadAction',
  'HilosPageTabLink',
  'HilosStaticPageNavigation',
]
/** Public legal document APIs added for the Privacy page and shared SDK reader. */
const publicLegalValues = [
  'LEGAL_PRIVACY_SECTION',
  'PRIVACY_REVISION_TEXT_ACTION',
  'legalPrivacySchema',
  'legalPrivacySectionSchema',
  'LEGAL_PRIVACY_COPY',
  'createHilosLegalPrivacyStore',
  'describeHilosLegalPrivacyReader',
  'legalPublicDocumentSchema',
  'legalPublicDocumentSectionSchema',
  'LEGAL_PUBLIC_DOCUMENT_COPY',
  'createHilosLegalPublicDocumentStore',
  'describeHilosLegalPublicDocumentReader',
]
const publicLegalTypes = [
  'HilosLegalPrivacy',
  'HilosLegalPrivacyReaderState',
  'HilosLegalPrivacyReader',
  'HilosLegalPrivacyReaderContext',
  'HilosLegalPrivacyStore',
  'HilosLegalPublicDocument',
  'HilosLegalPublicDocumentCopy',
  'HilosLegalPublicDocumentReaderState',
  'HilosLegalPublicDocumentReader',
  'HilosLegalPublicDocumentReaderContext',
  'HilosLegalPublicDocumentOptions',
  'HilosLegalPublicDocumentStore',
]
/** The moment formatter's explicit additions to the frozen legacy snapshot. */
const momentValues = ['formatMoment']
const momentTypes = ['MomentFormatOptions']
/** One common live-loss controller, shared by base and the compatibility root. */
const accessLossValues = [
  'ACCESS_LOSS_COPY',
  'bindAccessLoss',
  'hilosAccessLossReason',
  'sessionAccessLossReason',
]
const accessLossTypes = ['AccessLossReason']
/** The profile picker is one shared base/root implementation and type owner. */
const timeZonePickerValues = ['createHilosTimeZonePicker']
const timeZonePickerTypes = ['HilosTimeZonePicker', 'HilosTimeZonePickerRow']
/** The held name snapshot has one eager owner, shared by root and base. */
const profileNameStateValues = [
  'bindProfileNameState',
  'hilosProfileNameAccess',
  'hilosProfileNameIdentity',
  'hilosProfileNameState',
  'hilosProfileNameStateKnown',
  'profileNameStateSchema',
  'SIGNAL_PROFILE_NAME_STATE',
]
const profileNameStateTypes = [
  'HilosProfileNameChange',
  'HilosProfileNameState',
]
/** Tab-local edit data has one owner without a runtime edge to the rename flow. */
const profileRenameMemoryValues = [
  'clearProfileRenameMemory',
  'PROFILE_RENAME_BROWSER_VALUE',
  'PROFILE_RENAME_MEMORY_STORAGE_KEY',
  'profileRenameMemorySchema',
  'readProfileRenameMemory',
  'writeProfileRenameMemory',
]
const profileRenameMemoryTypes = ['HilosProfileRenameMemory']
/** Detached edit data is shared by root and base, owned by the row session. */
const rowEditTypes = ['HilosRowEditCheckpoint']
/** The overview's new API belongs only to the lazy Analytics section. */
const analyticsOverviewValues = [
  'ANALYTICS_OVERVIEW_SECTION',
  'ANALYTICS_OVERVIEW_COPY',
  'hilosAnalyticsOverviewSnapshotSchema',
  'createHilosAnalyticsOverview',
  'analyticsOverviewTiles',
]
const analyticsOverviewTypes = [
  'HilosAnalyticsOverviewSnapshot',
  'HilosAnalyticsOverview',
  'HilosAnalyticsOverviewTile',
]
/** Period and People are section-only APIs, beside the Analytics overview. */
const analyticsPeriodValues = [
  'ANALYTICS_PERIOD_FILTER',
  'ANALYTICS_PERIOD_TIME_ZONE_FILTER',
  'ANALYTICS_PERIOD_WEEK_START_TS_FILTER',
  'ANALYTICS_PERIOD_DAY_START_TS_FILTER',
  'ANALYTICS_PERIOD_HOUR_START_TS_FILTER',
  'ANALYTICS_PERIOD_COPY',
  'createHilosAnalyticsPeriod',
]
const analyticsPeriodTypes = [
  'AnalyticsPeriod',
  'AnalyticsPeriodContext',
  'HilosAnalyticsPeriodOptions',
  'HilosAnalyticsPeriod',
]
const analyticsPeopleValues = [
  'ANALYTICS_PEOPLE_TABLE',
  'ANALYTICS_PEOPLE_SLOT',
  'ANALYTICS_PERSON_ROW_KEY_FIELD',
  'ANALYTICS_PERSON_NAME_FIELD',
  'ANALYTICS_PERSON_SESSION_COUNT_FIELD',
  'ANALYTICS_PERSON_LAST_SEEN_TS_FIELD',
  'resolveHilosAnalyticsPersonRow',
  'formatAnalyticsPersonName',
  'formatAnalyticsPeopleTime',
  'createHilosAnalyticsPeopleTable',
]
const analyticsPeopleTypes = [
  'HilosAnalyticsPersonRow',
  'HilosAnalyticsPeopleOptions',
  'HilosAnalyticsPeopleTable',
]
/** The browser-session list belongs only to its lazy Analytics section. */
const analyticsSessionListValues = [
  'ANALYTICS_BROWSER_SESSION_LIST_TABLE',
  'ANALYTICS_BROWSER_SESSION_LIST_SLOT',
  'ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM',
  'ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD',
  'ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD',
  'ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_NAME_FIELD',
  'ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD',
  'ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD',
  'ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD',
  'ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD',
  'readAnalyticsBrowserSessionsAddress',
  'analyticsBrowserSessionsPath',
  'resolveHilosAnalyticsBrowserSessionListRow',
  'formatAnalyticsBrowserSessionListPerson',
  'formatAnalyticsBrowserSessionListTime',
  'createHilosAnalyticsBrowserSessionListTable',
]
const analyticsSessionListTypes = [
  'HilosAnalyticsBrowserSessionListRow',
  'HilosAnalyticsBrowserSessionListAddress',
  'HilosAnalyticsBrowserSessionListOptions',
  'HilosAnalyticsBrowserSessionListTable',
]
/** Closed table text policy additions, shared by root and base. */
const tableTextValues = ['hilosTableRowHeight', 'hilosTableTextClass']
const tableTextTypes = [
  'HilosTableLineCount',
  'HilosTableTextRem',
  'HilosTableRowHeight',
]
/** Explicit table focus return additions, with one DOM owner for root and base. */
const tableFocusReturnValues = ['captureTableFocusReturnTarget']
const tableFocusReturnTypes = ['TableFocusReturnRow']
/** New communications APIs stay on their lazy section, outside root and base. */
const channelSummaryValues = [
  'CHANNEL_SUMMARY_DATA',
  'channelSummarySchema',
  'createHilosChannelSummary',
]
const channelSummaryTypes = ['HilosChannelSummary']
/** Frame APIs are lazy-section additions, without new root or base exports. */
const letterFrameValues = [
  'LETTER_FRAME_DATA',
  'LETTER_FRAME_FIELD',
  'letterFrameSchema',
  'createHilosChannelFrame',
]
const letterFrameTypes = ['HilosLetterFrame']
const letterFrameEditValues = ['createHilosChannelFrameEdit']
const letterFrameEditTypes = [
  'HilosChannelFrameEditFields',
  'HilosChannelFrameEditForm',
]
/** Connection interaction APIs added to the shared shell's core surface. */
const connectionInteractionValues = [
  'bindInteractionBlock',
  'createConnectionInteractionState',
  'CONNECTION_INTERACTION_COPY',
  'HILOS_CONNECTION_STATUS_TEXT_ID',
  'HILOS_INTERACTION_BLOCKED_ATTR',
  'HILOS_INTERACTION_REGION_ATTR',
]
const connectionInteractionTypes = ['ConnectionInteractionState']
/**
 * The photo's own check frame gave way to the shared moderation one: the two
 * names it took out of the frozen snapshot and the three it added, by hand.
 */
const moderationRemovedValues = [
  'SIGNAL_PROFILE_PHOTO_CHECK',
  'profilePhotoCheckSchema',
]
const moderationValues = [
  'PROFILE_PHOTO_KIND',
  'SIGNAL_MODERATION_CHECK',
  'moderationCheckSchema',
]
const daemonNodeValues = [
  'DAEMON_NODE_OVERVIEW_DATA',
  'daemonNodeOverviewSchema',
  'readHilosDaemonNodeOverview',
  'readHilosDaemonEnvOrphansPage',
]
/** Approved Status exports; keep the legacy snapshot and base graph unchanged. */
const statusValues = [
  'HILOS_STATUS_ACTION_COPY',
  'HILOS_STATUS_ADD_UPDATE_ACTION',
  'HILOS_STATUS_CANCEL_PUBLICATION_ACTION',
  'HILOS_STATUS_COMPONENTS_COPY',
  'HILOS_STATUS_COMPONENTS_TABLE',
  'HILOS_STATUS_COMPONENT_SLOT',
  'HILOS_STATUS_COPY',
  'HILOS_STATUS_DATA',
  'HILOS_STATUS_PUBLISH_NOW_ACTION',
  'STATUS_COMPONENT_AGENT_TYPE_FIELD',
  'STATUS_COMPONENT_DESCRIPTION_FIELD',
  'STATUS_COMPONENT_FILTER_STATE',
  'STATUS_COMPONENT_NAME_FIELD',
  'STATUS_COMPONENT_STATE_FIELD',
  'createHilosStatusActions',
  'createHilosStatusComponentsTable',
  'createHilosStatusIncidentController',
  'createHilosStatusState',
  'hilosStatusIncidentCard',
  'resolveHilosStatusComponentRow',
  'statusComponentSchema',
  'statusComponentStateSchema',
  'statusIncidentSchema',
  'statusIncidentUpdateSchema',
  'statusOverviewSchema',
]
const statusTypes = [
  'HilosStatusActions',
  'HilosStatusComponentRow',
  'HilosStatusComponentState',
  'HilosStatusComponentsContext',
  'HilosStatusComponentsTable',
  'HilosStatusContext',
  'HilosStatusIncident',
  'HilosStatusIncidentCard',
  'HilosStatusIncidentController',
  'HilosStatusIncidentDialog',
  'HilosStatusIncidentOperation',
  'HilosStatusIncidentUpdate',
  'HilosStatusOverviewData',
  'HilosStatusState',
  'HilosStatusSummary',
]
/** The public file reader is its own lazy entry, separate from admin Status. */
const publicStatusValues = [
  'createPublicStatus',
  'publicStatusBanner',
  'publicStatusCandidateSchema',
  'publicStatusComponentSchema',
  'publicStatusDayStrips',
  'publicStatusFileSchema',
  'publicStatusHeartbeatSchema',
  'publicStatusHistory',
  'publicStatusMaintenancePeriodSchema',
  'publicStatusOutageSchema',
  'publicStatusOutageViews',
  'publicStatusOverall',
  'publicStatusUpdateSchema',
  'publicStatusWorkSchema',
  'readPublicStatusFiles',
]
const publicStatusTypes = [
  'PublicStatus',
  'PublicStatusBanner',
  'PublicStatusCandidate',
  'PublicStatusComponent',
  'PublicStatusDay',
  'PublicStatusDayState',
  'PublicStatusDayStrip',
  'PublicStatusFetch',
  'PublicStatusFile',
  'PublicStatusFreshness',
  'PublicStatusHeartbeat',
  'PublicStatusHistoryEntry',
  'PublicStatusMaintenancePeriod',
  'PublicStatusMaintenanceView',
  'PublicStatusOptions',
  'PublicStatusOutage',
  'PublicStatusOutageView',
  'PublicStatusPair',
  'PublicStatusReadResult',
  'PublicStatusState',
  'PublicStatusUpdate',
  'PublicStatusWork',
]
/** Restore-card selectors added on the maintenance frame. The frozen snapshot stays as it was. */
const maintenanceFrameValues = [
  'hilosMaintenanceOtherBrowserLine',
  'hilosMaintenanceRestoreLadder',
]
/** Restore controls added beside the manual pass. The frozen snapshot stays as it was. */
const maintenanceRestoreActionValues = [
  'MAINTENANCE_RESTORE_CLOSE_ACTION',
  'MAINTENANCE_RESTORE_OPEN_ACTION',
  'MAINTENANCE_RESTORE_PASS_ACTION',
]
/** Restore dialog words, shared by the compatibility root and the section entry. */
const maintenanceRestoreCopyValues = [
  'HILOS_MAINTENANCE_RESTORE_CLOSE_COPY',
  'HILOS_MAINTENANCE_RESTORE_OPEN_COPY',
  'HILOS_MAINTENANCE_RESTORE_PASS_COPY',
]
const maintenanceFrameTypes = ['HilosMaintenanceRestoreLadder']
/** The standard self-rename binding, shared by the compatibility root and base. */
const profileRenameValues = [
  'PROFILE_NAME_SET_ACTION',
  'createHilosProfileRename',
]
/** Presentation copy shared by the user section and compatibility root. */
const userOverviewValues = ['HILOS_USER_OVERVIEW_COPY']
/** Approved Moderation section exports; keep the legacy snapshot and base graph unchanged. */
const adminModerationValues = [
  'HILOS_MODERATION_COPY',
  'HILOS_MODERATION_KINDS_TABLE',
  'HILOS_MODERATION_KIND_SLOT',
  'HilosModerationKindRowKey',
  'createHilosModerationKindsTable',
  'moderationKindPath',
  'resolveHilosModerationKindRow',
]
const adminModerationTypes = [
  'HilosModerationContext',
  'HilosModerationKindRow',
  'HilosModerationKindsTable',
]
/** Detail APIs stay exclusive to their lazy section. */
const adminModerationKindValues = ['createHilosModerationKind']
const adminModerationKindTypes = ['HilosModerationKind']
const adminModerationRulesValues = [
  'HILOS_MODERATION_RULES_TABLE',
  'HILOS_MODERATION_RULE_SLOT',
  'HilosModerationRuleRowKey',
  'createHilosModerationRules',
  'resolveHilosModerationRuleRow',
  'moderationKindRulesPath',
]
const adminModerationRulesTypes = [
  'HilosModerationRuleRow',
  'HilosModerationRules',
]
const adminModerationRuleAddValues = [
  'HILOS_MODERATION_RULE_ADD_ACTION',
  'HILOS_MODERATION_RULE_ADD_COPY',
  'createHilosModerationRuleAdd',
]
const adminModerationRuleAddTypes = [
  'HilosModerationRulesContext',
  'HilosModerationRuleAdd',
]
/** The standalone noisy-column viewport's explicit public additions. */
const changeLogNoisyValues = [
  'CHANGE_LOG_NOISY_COLUMN_FIELD',
  'CHANGE_LOG_NOISY_REASON_FIELD',
  'createHilosChangeLogNoisyColumnsTable',
  'resolveHilosChangeLogNoisyColumnsRow',
]
const changeLogNoisyTypes = [
  'HilosChangeLogNoisyColumnsRow',
  'HilosChangeLogNoisyColumnsTable',
]
const expectedValues = [
  ...daemonNodeValues,
  ...changeLogNoisyValues,
  ...legacy.value.filter((name) => !moderationRemovedValues.includes(name)),
  ...timeZoneValues,
  ...logViewerValues,
  ...i18nOverviewValues,
  ...pageHeadValues,
  ...publicLegalValues,
  ...momentValues,
  ...accessLossValues,
  ...timeZonePickerValues,
  ...profileNameStateValues,
  ...profileRenameMemoryValues,
  ...tableTextValues,
  ...tableFocusReturnValues,
  ...moderationValues,
  ...adminModerationValues,
  ...connectionInteractionValues,
  ...statusValues,
  ...publicStatusValues,
  ...profileRenameValues,
  ...userOverviewValues,
  ...maintenanceFrameValues,
  ...maintenanceRestoreActionValues,
  ...maintenanceRestoreCopyValues,
  'hilosAnalyticsPerson',
  'PROFILE_SESSION_BROWSER_TIME_ZONE_FIELD',
  'hilosProfileDeviceKind',
].sort()

/**
 * Follow emitted ESM edges, so type-only imports never become fake runtime edges.
 * A real tsc dist walk is also run against the SDK build at handover.
 *
 * @param entry Source entry relative to src.
 */
function runtimeClosure(entry: string): string[] {
  const visited = new Set<string>()
  function visit(file: string): void {
    if (visited.has(file)) return
    visited.add(file)
    const output = ts.transpileModule(readFileSync(file, 'utf8'), {
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.ESNext,
      },
    }).outputText
    const module = ts.createSourceFile(
      file,
      output,
      ts.ScriptTarget.Latest,
      true,
      ts.ScriptKind.JS,
    )
    for (const statement of module.statements) {
      if (
        (!ts.isImportDeclaration(statement) &&
          !ts.isExportDeclaration(statement)) ||
        statement.moduleSpecifier === undefined ||
        !ts.isStringLiteral(statement.moduleSpecifier)
      ) {
        continue
      }
      const specifier = statement.moduleSpecifier.text
      if (specifier.startsWith('.')) {
        expect(specifier.endsWith('.js')).toBe(true)
        visit(resolve(dirname(file), specifier.replace(/\.js$/, '.ts')))
      } else {
        expect(['@vue/reactivity', 'qrcode-generator', 'zod']).toContain(
          specifier,
        )
      }
    }
  }
  visit(join(sourceRoot, entry))

  return [...visited].map((file) => relative(sourceRoot, file)).sort()
}

describe('core public entries', () => {
  it('publishes base, public Status and exactly fifteen admin sections with one tsc output per source', () => {
    const manifest = JSON.parse(
      readFileSync(join(packageRoot, 'package.json'), 'utf8'),
    ) as {
      exports: Record<string, Record<string, string>>
    }
    expect(Object.keys(sections)).toHaveLength(15)
    expect(Object.keys(manifest.exports).sort()).toEqual(
      [
        '.',
        './build/initial-bundle-budget',
        ...Object.keys(publicEntries),
      ].sort(),
    )
    for (const subpath of ['.', ...Object.keys(publicEntries)]) {
      const entry =
        subpath === '.'
          ? 'index'
          : `${subpath.slice(2).replace('change-log', 'changeLog')}/index`
      expect(manifest.exports[subpath]).toEqual({
        development: `./src/${entry}.ts`,
        types: `./dist/${entry}.d.ts`,
        import: `./dist/${entry}.js`,
        default: `./dist/${entry}.js`,
      })
      expect(
        readFileSync(join(packageRoot, 'src', `${entry}.ts`), 'utf8'),
      ).not.toBe('')
    }
    expect(manifest.exports['./build/initial-bundle-budget']).toEqual({
      types: './bin/initial-bundle-budget.d.mts',
      import: './bin/initial-bundle-budget.mjs',
    })
  })

  it('preserves every legacy value and one instance across root, base and sections', () => {
    expect(Object.keys(core).sort()).toEqual(expectedValues)
    const names = new Set<string>()
    const root: Record<string, unknown> = core
    for (const [entry, api] of Object.entries(publicEntries)) {
      for (const [name, value] of Object.entries(api)) {
        names.add(name)
        if (
          entry === './admin/analytics' &&
          analyticsOverviewValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              analyticsOverview[name as keyof typeof analyticsOverview],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/analytics' &&
          analyticsPeriodValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              analyticsPeriod[name as keyof typeof analyticsPeriod],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/analytics' &&
          analyticsPeopleValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              analyticsPeople[name as keyof typeof analyticsPeople],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/analytics' &&
          analyticsSessionListValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              analyticsSessionList[name as keyof typeof analyticsSessionList],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/communications' &&
          channelSummaryValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              channelSummary[name as keyof typeof channelSummary],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/communications' &&
          letterFrameValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(value, letterFrame[name as keyof typeof letterFrame]),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/communications' &&
          letterFrameEditValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              letterFrameEdit[name as keyof typeof letterFrameEdit],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/moderation' &&
          adminModerationRulesValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              moderationRules[name as keyof typeof moderationRules],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/moderation' &&
          adminModerationRuleAddValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              moderationRuleAdd[name as keyof typeof moderationRuleAdd],
            ),
            name,
          ).toBe(true)
        } else if (
          entry === './admin/moderation' &&
          adminModerationKindValues.includes(name)
        ) {
          expect(root, name).not.toHaveProperty(name)
          expect(base, name).not.toHaveProperty(name)
          expect(
            Object.is(
              value,
              moderationKind[name as keyof typeof moderationKind],
            ),
            name,
          ).toBe(true)
        } else {
          expect(Object.is(value, root[name]), name).toBe(true)
        }
      }
    }
    expect([...names].sort()).toEqual(
      [
        ...expectedValues,
        ...analyticsOverviewValues,
        ...analyticsPeriodValues,
        ...analyticsPeopleValues,
        ...analyticsSessionListValues,
        ...channelSummaryValues,
        ...letterFrameValues,
        ...letterFrameEditValues,
        ...adminModerationKindValues,
        ...adminModerationRulesValues,
        ...adminModerationRuleAddValues,
      ].sort(),
    )
    expect(base.hilosLegalDocumentLabel('terms')).toBe('Terms')
    expect(base.hilosLegalDocumentLabel('project-key')).toBe('project-key')
    for (const name of timeZoneValues) {
      expect(base, name).toHaveProperty(name)
      expect(Object.is(base[name as keyof typeof base], root[name]), name).toBe(
        true,
      )
    }
    for (const name of [
      ...pageHeadValues,
      ...publicLegalValues,
      ...momentValues,
      ...accessLossValues,
      ...timeZonePickerValues,
      ...profileNameStateValues,
      ...profileRenameMemoryValues,
      ...tableTextValues,
      ...tableFocusReturnValues,
      ...moderationValues,
      ...connectionInteractionValues,
      ...profileRenameValues,
    ]) {
      expect(base, name).toHaveProperty(name)
      expect(Object.is(base[name as keyof typeof base], root[name]), name).toBe(
        true,
      )
    }
    expect(base.PROFILE_SESSION_BROWSER_TIME_ZONE_FIELD).toBe(
      core.PROFILE_SESSION_BROWSER_TIME_ZONE_FIELD,
    )
    expect(base.hilosProfileDeviceKind).toBe(core.hilosProfileDeviceKind)
    expect(base.hilosTimeZone).toBe(core.hilosTimeZone)
    expect(base.bindTimeZoneState).toBe(core.bindTimeZoneState)
    expect(base.createHilosTimeZonePicker).toBe(
      timeZonePicker.createHilosTimeZonePicker,
    )
    for (const name of profileNameStateValues) {
      const owner: Record<string, unknown> = profileNameState
      expect((base as Record<string, unknown>)[name], name).toBe(owner[name])
    }
    for (const name of profileRenameMemoryValues) {
      const owner: Record<string, unknown> = profileRenameMemory
      expect((base as Record<string, unknown>)[name], name).toBe(owner[name])
    }
    for (const api of [core, base]) {
      expect(api).not.toHaveProperty('formatTimeZoneChoiceMoment')
      expect(api).not.toHaveProperty('formatSnapshotMoment')
    }
    expect(base.captureTableFocusReturnTarget).toBe(
      tableFocusReturn.captureTableFocusReturnTarget,
    )
    expect(base.hilosNotifications).toBe(core.hilosNotifications)
    expect(base.hilosLegalReconsent).toBe(core.hilosLegalReconsent)
    expect(base.createHilosConnection().connection).toBeInstanceOf(
      core.HilosConnection,
    )
    expect(backup.BACKUP_SIGNAL_SCHEMAS).toBe(base.BACKUP_SIGNAL_SCHEMAS)
    expect(logs.LOGS_SETTINGS_SIGNAL_SCHEMAS).toBe(
      base.LOGS_SETTINGS_SIGNAL_SCHEMAS,
    )
  })

  it('shares the restore shell implementation with every compatibility path', async () => {
    const former = await import('../../src/admin/backup/hilosBackups.js')
    for (const name of [
      'backupProgressPercent',
      'createBackupProgressClock',
      'createHilosRestoreProgress',
      'elapsedSecondsSince',
      'formatBackupEta',
      'formatBackupProgressLabel',
      'formatRestoreOutcomeLine',
      'formatRestorePhaseLine',
    ] as const) {
      for (const api of [core, base, backup, former]) {
        expect(api[name], name).toBe(restoreProgress[name])
      }
    }
    for (const api of [core, base, backup, former]) {
      expect(Object.keys(api)).not.toContain('PERCENT_SCALE')
    }
    expect(runtimeClosure('connection/restoreProgress.ts')).toEqual([
      'connection/restoreProgress.ts',
      'protocol/backupSignals.ts',
      'state/signal.ts',
    ])
  })

  it('keeps relocated schemas identical through their former direct modules', async () => {
    const formerModules = await Promise.all([
      import('../../src/admin/backup/hilosBackups.js'),
      import('../../src/admin/legal/hilosLegal.js'),
      import('../../src/admin/legal/hilosLegalAcceptancesExport.js'),
      import('../../src/admin/logs/hilosLogRotations.js'),
      import('../../src/admin/logs/hilosLogKeys.js'),
      import('../../src/admin/logs/hilosLogsOverview.js'),
      import('../../src/admin/logs/hilosLogSettings.js'),
      import('../../src/admin/logs/hilosLogViewer.js'),
      import('../../src/admin/logs/hilosLogWorkers.js'),
      import('../../src/admin/maintenance/hilosMaintenance.js'),
      import('../../src/admin/settings/hilosSettingPresets.js'),
      import('../../src/admin/users/hilosUsers.js'),
    ])
    for (const api of formerModules) {
      for (const [name, value] of Object.entries(api)) {
        if (name in base) {
          expect(Object.is(value, base[name as keyof typeof base]), name).toBe(
            true,
          )
        }
      }
    }
    const presets = settings.settingPresetsSignalSchemas('test_presets')
    expect(presets.test_presets).toBe(
      logs.LOGS_SETTINGS_SIGNAL_SCHEMAS[logs.LOG_SETTINGS_SIGNAL],
    )
  })

  it('exports the public reader once, outside base and admin Status', () => {
    expect(Object.keys(publicStatus).sort()).toEqual(
      [...publicStatusValues].sort(),
    )
    for (const name of publicStatusValues) {
      expect(base, name).not.toHaveProperty(name)
      expect(status, name).not.toHaveProperty(name)
      expect(core[name as keyof typeof core]).toBe(
        publicStatus[name as keyof typeof publicStatus],
      )
    }
    const closure = runtimeClosure('status/index.ts')
    expect(closure).not.toContain('index.ts')
    expect(closure.filter((file) => file.startsWith('admin/'))).toEqual([])
  })

  it('preserves legacy types and resolves each entry to the same declaration', () => {
    const entries = ['index.ts', 'base/index.ts', 'status/index.ts'].concat(
      Object.keys(sections).map(
        (section) =>
          `admin/${section.replace('change-log', 'changeLog')}/index.ts`,
      ),
    )
    const program = ts.createProgram(
      entries.map((entry) => join(sourceRoot, entry)),
      {
        module: ts.ModuleKind.ESNext,
        moduleResolution: ts.ModuleResolutionKind.Bundler,
        target: ts.ScriptTarget.ES2022,
        skipLibCheck: true,
        noEmit: true,
      },
    )
    const checker = program.getTypeChecker()
    function exportsOf(entry: string): Map<string, ts.Symbol> {
      const source = program.getSourceFile(join(sourceRoot, entry))!
      const symbol = checker.getSymbolAtLocation(source)!

      return new Map(
        checker
          .getExportsOfModule(symbol)
          .map((exported) => [
            exported.name,
            exported.flags & ts.SymbolFlags.Alias
              ? checker.getAliasedSymbol(exported)
              : exported,
          ]),
      )
    }
    const root = exportsOf('index.ts')
    const shared = exportsOf('connection/restoreProgress.ts')
    const former = exportsOf('admin/backup/hilosBackups.ts')
    for (const name of [
      'HilosProgressAnchors',
      'HilosRestoreProgress',
      'HilosBackupProgressClock',
    ]) {
      expect(shared.get(name), name).toBe(root.get(name))
      expect(former.get(name), name).toBe(root.get(name))
    }
    expect(
      [...root]
        .filter(([, symbol]) => symbol.flags & ts.SymbolFlags.Type)
        .map(([name]) => name)
        .sort(),
    ).toEqual(
      [
        ...legacy.type,
        ...changeLogNoisyTypes,
        'HilosProfileRenameState',
        'HilosProfileRenameFlowOptions',
        'HilosAnalyticsPerson',
        'HilosDaemonEnvOrphansPageState',
        'HilosDaemonNodeOverviewData',
        'HilosProfileDeviceKind',
        ...i18nOverviewTypes,
        ...pageHeadTypes,
        ...publicLegalTypes,
        ...momentTypes,
        ...accessLossTypes,
        ...timeZonePickerTypes,
        ...profileNameStateTypes,
        ...profileRenameMemoryTypes,
        ...rowEditTypes,
        ...tableTextTypes,
        ...tableFocusReturnTypes,
        ...connectionInteractionTypes,
        ...statusTypes,
        ...publicStatusTypes,
        ...adminModerationTypes,
        ...maintenanceFrameTypes,
      ].sort(),
    )
    const baseExports = exportsOf('base/index.ts')
    for (const name of [
      ...pageHeadTypes,
      ...publicLegalTypes,
      ...momentTypes,
      ...accessLossTypes,
      ...momentValues,
      ...accessLossValues,
      ...timeZonePickerTypes,
      ...timeZonePickerValues,
      ...profileNameStateTypes,
      ...profileNameStateValues,
      ...profileRenameMemoryTypes,
      ...profileRenameMemoryValues,
      'HilosProfileRenameState',
      'HilosProfileRenameFlowOptions',
      ...rowEditTypes,
      ...tableTextTypes,
      ...tableTextValues,
      ...tableFocusReturnTypes,
      ...tableFocusReturnValues,
      ...connectionInteractionTypes,
      ...connectionInteractionValues,
      'HilosProfileDeviceKind',
      'hilosProfileDeviceKind',
    ]) {
      expect(root.get(name), name).toBeDefined()
      expect(baseExports.get(name), name).toBe(root.get(name))
    }
    const momentExports = exportsOf('format/moment.ts')
    for (const name of [...momentValues, ...momentTypes]) {
      expect(root.get(name), name).toBe(momentExports.get(name))
    }
    const pickerExports = exportsOf('timeZone/timeZonePicker.ts')
    for (const name of [...timeZonePickerValues, ...timeZonePickerTypes]) {
      expect(root.get(name), name).toBe(pickerExports.get(name))
    }
    const nameStateExports = exportsOf('profile/profileNameState.ts')
    for (const name of [...profileNameStateValues, ...profileNameStateTypes]) {
      expect(root.get(name), name).toBe(nameStateExports.get(name))
    }
    const rowEditExports = exportsOf('conflict/rowEditSession.ts')
    for (const name of rowEditTypes) {
      expect(root.get(name), name).toBe(rowEditExports.get(name))
    }
    for (const name of ['formatTimeZoneChoiceMoment', 'formatSnapshotMoment']) {
      expect(momentExports.has(name), name).toBe(true)
      expect(root.has(name), name).toBe(false)
      expect(baseExports.has(name), name).toBe(false)
    }
    const focusReturnExports = exportsOf('dom/tableFocusReturn.ts')
    for (const name of [...tableFocusReturnValues, ...tableFocusReturnTypes]) {
      expect(root.get(name), name).toBe(focusReturnExports.get(name))
    }
    const overviewExports = exportsOf(
      'admin/analytics/hilosAnalyticsOverview.ts',
    )
    const overviewNames = [
      ...analyticsOverviewValues,
      ...analyticsOverviewTypes,
    ]
    const periodExports = exportsOf('admin/analytics/hilosAnalyticsPeriod.ts')
    const periodNames = [...analyticsPeriodValues, ...analyticsPeriodTypes]
    const peopleExports = exportsOf('admin/analytics/hilosAnalyticsPeople.ts')
    const peopleNames = [...analyticsPeopleValues, ...analyticsPeopleTypes]
    const sessionListExports = exportsOf(
      'admin/analytics/hilosAnalyticsBrowserSessionList.ts',
    )
    const sessionListNames = [
      ...analyticsSessionListValues,
      ...analyticsSessionListTypes,
    ]
    const names = new Set<string>()
    const summaryExports = exportsOf(
      'admin/communications/hilosCommunicationsChannel.ts',
    )
    const sectionOnly = [...channelSummaryValues, ...channelSummaryTypes]
    const frameExports = exportsOf(
      'admin/communications/hilosCommunicationsChannelFrame.ts',
    )
    const frameNames = [...letterFrameValues, ...letterFrameTypes]
    const frameEditExports = exportsOf(
      'admin/communications/hilosCommunicationsChannelFrameEdit.ts',
    )
    const frameEditNames = [...letterFrameEditValues, ...letterFrameEditTypes]
    const rulesExports = exportsOf('admin/moderation/hilosModerationRules.ts')
    const rulesNames = [
      ...adminModerationRulesValues,
      ...adminModerationRulesTypes,
    ]
    const ruleAddExports = exportsOf(
      'admin/moderation/hilosModerationRuleAdd.ts',
    )
    const ruleAddNames = [
      ...adminModerationRuleAddValues,
      ...adminModerationRuleAddTypes,
    ]
    const kindExports = exportsOf('admin/moderation/hilosModerationKind.ts')
    const kindNames = [
      ...adminModerationKindValues,
      ...adminModerationKindTypes,
    ]
    for (const entry of entries.slice(1)) {
      for (const [name, symbol] of exportsOf(entry)) {
        if (
          entry === 'admin/analytics/index.ts' &&
          overviewNames.includes(name)
        ) {
          expect(root.get(name), name).toBeUndefined()
          expect(baseExports.get(name), name).toBeUndefined()
          expect(Object.is(symbol, overviewExports.get(name)), name).toBe(true)
        } else if (
          entry === 'admin/analytics/index.ts' &&
          periodNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(periodExports.get(name))
        } else if (
          entry === 'admin/analytics/index.ts' &&
          peopleNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(peopleExports.get(name))
        } else if (
          entry === 'admin/analytics/index.ts' &&
          sessionListNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(sessionListExports.get(name))
        } else if (
          entry === 'admin/communications/index.ts' &&
          sectionOnly.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(summaryExports.get(name))
        } else if (
          entry === 'admin/communications/index.ts' &&
          frameNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(frameExports.get(name))
        } else if (
          entry === 'admin/communications/index.ts' &&
          frameEditNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(frameEditExports.get(name))
        } else if (
          entry === 'admin/moderation/index.ts' &&
          rulesNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(rulesExports.get(name))
        } else if (
          entry === 'admin/moderation/index.ts' &&
          ruleAddNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(ruleAddExports.get(name))
        } else if (
          entry === 'admin/moderation/index.ts' &&
          kindNames.includes(name)
        ) {
          expect(root.has(name), name).toBe(false)
          expect(baseExports.has(name), name).toBe(false)
          expect(symbol, `${entry}:${name}`).toBe(kindExports.get(name))
        } else {
          expect(Object.is(symbol, root.get(name)), `${entry}:${name}`).toBe(
            true,
          )
        }
        names.add(name)
      }
    }
    expect([...names].sort()).toEqual(
      [
        ...root.keys(),
        ...overviewNames,
        ...periodNames,
        ...peopleNames,
        ...sessionListNames,
        ...sectionOnly,
        ...frameNames,
        ...frameEditNames,
        ...kindNames,
        ...rulesNames,
        ...ruleAddNames,
      ].sort(),
    )
  })

  it('keeps the entire base runtime closure free of public Status and admin implementations', () => {
    const closure = runtimeClosure('base/index.ts')
    expect(closure).not.toContain('index.ts')
    expect(closure.filter((file) => file.startsWith('status/'))).toEqual([])
    expect(closure.filter((file) => file.startsWith('admin/'))).toEqual([
      'admin/identity/hilosPageIdentity.ts',
      'admin/identity/hilosPageInstanceName.ts',
      'admin/viewMode.ts',
    ])
    expect(closure.filter((file) => file.endsWith('/index.ts'))).toEqual([
      'base/index.ts',
    ])
    expect(closure).toContain('connection/restoreProgress.ts')
    expect(closure).toContain('format/moment.ts')
    expect(closure).toContain('timeZone/timeZonePicker.ts')
    expect(closure).toContain('profile/profileNameState.ts')
    expect(runtimeClosure('protocol/profileNameStateSignal.ts')).toEqual([
      'protocol/profileNameStateSignal.ts',
    ])
    expect(runtimeClosure('session/sessionScope.ts')).not.toContain(
      'profile/profileNameState.ts',
    )
    expect(closure).toContain('profile/profileRenameMemory.ts')
    expect(runtimeClosure('profile/profileRenameMemory.ts')).toEqual([
      'browser/browserStorage.ts',
      'browser/browserValue.ts',
      'profile/profileRenameMemory.ts',
    ])
    expect(closure).toContain('table/tableTextExpansion.ts')
    expect(runtimeClosure('table/tableTextExpansion.ts')).toEqual([
      'state/signal.ts',
      'table/tableTextExpansion.ts',
    ])
    for (const module of [
      'legalPublicDocument',
      'legalPrivacy',
      'legalTerms',
    ]) {
      expect(closure).toContain(`legal/${module}.ts`)
    }
    for (const protocol of [
      'accountStandingSignals',
      'backupSignals',
      'legalAcceptancesSignals',
      'logsSignals',
      'maintenanceSignals',
      'settingPresetsSignals',
      'statusSignals',
    ]) {
      expect(closure).toContain(`protocol/${protocol}.ts`)
    }
    expect(Object.keys(base)).not.toContain('createHilosBackupsTable')
    expect(Object.keys(base)).not.toContain('createHilosSettingPresets')
    expect(Object.keys(base)).not.toContain('legalAdminDocumentSchema')
    expect(Object.keys(base)).not.toContain('createHilosModerationKindsTable')
  })

  it.each([
    'base/index.ts',
    'bootstrap/bootHilos.ts',
    'conflict/rowEditSession.ts',
    'profile/profileNameState.ts',
    'profile/profileRename.ts',
    'auth/stepUp.ts',
    'profile/profileRenameMemory.ts',
    'browser/browserValues.ts',
    'protocol/profileNameStateSignal.ts',
    'routing/bindPageDeparture.ts',
    'session/accessLoss.ts',
    'session/sessionScope.ts',
    'connection/HilosConnection.ts',
    'connection/interactionBlock.ts',
    'dom/interactionBlock.ts',
    'dom/focusTrap.ts',
    'dom/tableFocusReturn.ts',
    'format/moment.ts',
    'timeZone/timeZoneState.ts',
    'timeZone/timeZonePicker.ts',
    'subscription/PageSubscription.ts',
    'subscription/bindTableViewport.ts',
    'table/TableViewportController.ts',
    'table/hilosTableColumn.ts',
    'table/tableFrame.ts',
    'table/tableTextExpansion.ts',
    'table/tableTextHeight.ts',
    'table/tableViewportGeometry.ts',
    'legal/legalTerms.ts',
    'legal/legalPublicDocument.ts',
    'legal/legalPrivacy.ts',
    'routing/PageRouter.ts',
    'routing/hilosPages.ts',
    'routing/hilosUnbuiltPages.ts',
  ])('keeps reviewed owner %s in the eager source/output inventory', (name) => {
    const inventory = JSON.parse(
      readFileSync(join(packageRoot, 'bin/core-initial-sources.json'), 'utf8'),
    ) as Record<string, { sourceSha256: string; outputSha256: string }>
    const file = join(sourceRoot, name)
    const content = readFileSync(file, 'utf8')
    const compiled = ts.transpileModule(content, {
      fileName: file,
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.ESNext,
        sourceMap: true,
        inlineSources: true,
      },
    }).outputText
    expect(inventory[`src/${name}`]).toEqual({
      sourceSha256: createHash('sha256').update(content).digest('hex'),
      outputSha256: createHash('sha256').update(compiled).digest('hex'),
    })
  })
})
