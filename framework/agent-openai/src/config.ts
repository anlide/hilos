import path from 'node:path';
import { z } from 'zod';

const envSchema = z.object({
  OPENAI_API_KEY: z.string().trim().optional(),
  OPENAI_MODEL: z.string().trim().default('gpt-5'),
  AGENT_OPENAI_HOST: z.string().trim().default('0.0.0.0'),
  AGENT_OPENAI_PORT: z.coerce.number().int().min(1).max(65535).default(9307),
  AGENT_OPENAI_DATA_DIR: z.string().trim().default('/data'),
  AGENT_OPENAI_WORKSPACE_ROOT: z.string().trim().default('/workspace'),
  AGENT_OPENAI_ALLOWED_ROOTS: z.string().trim().optional(),
  AGENT_OPENAI_REPORTS_DIR: z.string().trim().optional(),
  AGENT_OPENAI_RUNS_DIR: z.string().trim().optional(),
  AGENT_OPENAI_FINGERPRINT_FILE: z.string().trim().optional(),
  AGENT_OPENAI_MAX_FILE_SIZE_BYTES: z.coerce.number().int().positive().default(256_000),
  AGENT_OPENAI_MAX_LIST_FILES: z.coerce.number().int().positive().default(1_000),
  AGENT_OPENAI_REASONING_EFFORT: z.enum(['none', 'low', 'medium', 'high']).default('medium'),
  AGENT_OPENAI_PROMPT_TEMPLATE_VERSION: z.string().trim().default('2026-04-05'),
});

export interface AgentOpenAIConfig {
  openAiApiKey?: string;
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
  reasoningEffort: 'none' | 'low' | 'medium' | 'high';
  promptTemplateVersion: string;
}

export function loadConfig(): AgentOpenAIConfig {
  const parsed = envSchema.parse(process.env);
  const dataDir = path.resolve(parsed.AGENT_OPENAI_DATA_DIR);
  const workspaceRoot = path.resolve(parsed.AGENT_OPENAI_WORKSPACE_ROOT);
  const reportsDir = path.resolve(parsed.AGENT_OPENAI_REPORTS_DIR ?? path.join(dataDir, 'reports'));
  const runsDir = path.resolve(parsed.AGENT_OPENAI_RUNS_DIR ?? path.join(dataDir, 'runs'));
  const fingerprintFile = path.resolve(
    parsed.AGENT_OPENAI_FINGERPRINT_FILE ?? path.join(dataDir, 'fingerprints.json'),
  );

  const allowedRoots = (parsed.AGENT_OPENAI_ALLOWED_ROOTS ?? workspaceRoot)
    .split(',')
    .map((root) => root.trim())
    .filter(Boolean)
    .map((root) => path.resolve(root));

  return {
    openAiApiKey: parsed.OPENAI_API_KEY,
    model: parsed.OPENAI_MODEL,
    host: parsed.AGENT_OPENAI_HOST,
    port: parsed.AGENT_OPENAI_PORT,
    dataDir,
    workspaceRoot,
    reportsDir,
    runsDir,
    fingerprintFile,
    allowedRoots,
    maxFileSizeBytes: parsed.AGENT_OPENAI_MAX_FILE_SIZE_BYTES,
    maxListFiles: parsed.AGENT_OPENAI_MAX_LIST_FILES,
    reasoningEffort: parsed.AGENT_OPENAI_REASONING_EFFORT,
    promptTemplateVersion: parsed.AGENT_OPENAI_PROMPT_TEMPLATE_VERSION,
  };
}
