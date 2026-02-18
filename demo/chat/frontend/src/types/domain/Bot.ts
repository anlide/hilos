/**
 * Bot - matches Bot structure from DB
 *
 * Fields match backend/Database/Object/Item/Bot.php:
 * - id: ?int (TypeScript: number | null)
 * - name: string
 * - description: ?string
 * - style: ?string
 * - topics: ?string
 * - personality: ?string
 * - active: boolean
 */
export interface BotEntity {
  id: number | null
  name: string
  description: string | null
  style: string | null
  topics: string | null
  personality: string | null
  active: boolean
}
