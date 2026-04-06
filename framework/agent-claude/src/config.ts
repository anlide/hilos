import path from 'node:path';
import { z } from 'zod';

const envSchema = z.object({
  ANTHROPIC_API_KEY: z.string().trim().optional(),
  CLAUDE_MODEL: z.string().trim().default('claude-sonnet-4-6'),
  AGENT_CLAUDE_HOST: z.string().trim().default('0.0.0.0'),
  AGENT_CLAUDE_PORT: z.coerce.number().int().min(1).max(65535).default(9308),
  AGENT_CLAUDE_DATA_DIR: z.string().trim().default('/data'),
  AGENT_CLAUDE_WORKSPACE_ROOT: z.string().trim().default('/workspace'),
  AGENT_CLAUDE_ALLOWED_ROOTS: z.string().trim().optional(),
  AGENT_CLAUDE_REPORTS_DIR: z.string().trim().optional(),
  AGENT_CLAUDE_RUNS_DIR: z.string().trim().optional(),
  AGENT_CLAUDE_FINGERPRINT_FILE: z.string().trim().optional(),
  AGENT_CLAUDE_MAX_FILE_SIZE_BYTES: z.coerce.number().int().positive().default(256_000),
  AGENT_CLAUDE_MAX_LIST_FILES: z.coerce.number().int().positive().default(1_000),
  AGENT_CLAUDE_MAX_TURNS: z.coerce.number().int().positive().default(25),
  AGENT_CLAUDE_PROMPT_TEMPLATE_VERSION: z.string().trim().default('2026-04-05'),
});

export interface AgentClaudeConfig {
  anthropicApiKey?: string;
  model: string;
  host: string;
  port: number;
  dataDir: string;
  workspaceRoot: string;
  reportsDir: string;
  runsDir: string;
  fingerprintFile: string;
  allowedRoots: string[];
  maxFileSizeBytes: number;
  maxListFiles: number;
  maxTurns: number;
  promptTemplateVersion: string;
}

export function loadConfig(): AgentClaudeConfig {
  const parsed = envSchema.parse(process.env);
  const dataDir = path.resolve(parsed.AGENT_CLAUDE_DATA_DIR);
  const workspaceRoot = path.resolve(parsed.AGENT_CLAUDE_WORKSPACE_ROOT);
  const reportsDir = path.resolve(parsed.AGENT_CLAUDE_REPORTS_DIR ?? path.join(dataDir, 'reports'));
  const runsDir = path.resolve(parsed.AGENT_CLAUDE_RUNS_DIR ?? path.join(dataDir, 'runs'));
  const fingerprintFile = path.resolve(
    parsed.AGENT_CLAUDE_FINGERPRINT_FILE ?? path.join(dataDir, 'fingerprints.json'),
  );

  const allowedRoots = (parsed.AGENT_CLAUDE_ALLOWED_ROOTS ?? workspaceRoot)
    .split(',')
    .map((root) => root.trim())
    .filter(Boolean)
    .map((root) => path.resolve(root));

  return {
    anthropicApiKey: parsed.ANTHROPIC_API_KEY,
    model: parsed.CLAUDE_MODEL,
    host: parsed.AGENT_CLAUDE_HOST,
    port: parsed.AGENT_CLAUDE_PORT,
    dataDir,
    workspaceRoot,
    reportsDir,
    runsDir,
    fingerprintFile,
    allowedRoots,
    maxFileSizeBytes: parsed.AGENT_CLAUDE_MAX_FILE_SIZE_BYTES,
    maxListFiles: parsed.AGENT_CLAUDE_MAX_LIST_FILES,
    maxTurns: parsed.AGENT_CLAUDE_MAX_TURNS,
    promptTemplateVersion: parsed.AGENT_CLAUDE_PROMPT_TEMPLATE_VERSION,
  };
}