// @hilos/core/admin/moderation: lazy section APIs.

export {
  createHilosModerationKindsTable,
  resolveHilosModerationKindRow,
  moderationKindPath,
  HILOS_MODERATION_COPY,
  HILOS_MODERATION_KINDS_TABLE,
  HILOS_MODERATION_KIND_SLOT,
  HilosModerationKindRowKey,
  type HilosModerationContext,
  type HilosModerationKindRow,
  type HilosModerationKindsTable,
} from './hilosModeration.js'

export {
  createHilosModerationKind,
  type HilosModerationKind,
} from './hilosModerationKind.js'

export {
  HILOS_MODERATION_RULES_TABLE,
  HILOS_MODERATION_RULE_SLOT,
  HilosModerationRuleRowKey,
  resolveHilosModerationRuleRow,
  createHilosModerationRules,
  moderationKindRulesPath,
  type HilosModerationRuleRow,
  type HilosModerationRules,
} from './hilosModerationRules.js'

export {
  HILOS_MODERATION_RULE_ADD_ACTION,
  HILOS_MODERATION_RULE_ADD_COPY,
  createHilosModerationRuleAdd,
  type HilosModerationRulesContext,
  type HilosModerationRuleAdd,
} from './hilosModerationRuleAdd.js'
