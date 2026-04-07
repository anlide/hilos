import path from 'node:path';

import type { AgentProvider } from '@hilos/agent-core';
import { z } from 'zod';

export const PROVIDER_NAMES = ['claude', 'openai'] as const;

const envSchema = z.object({
  AGENT_HOST: z.string().trim().default('0.0.0.0'),
  AGENT_PORT: z.coerce.number().int().min(1).max(65535).default(9309),
  AGENT_DATA_DIR: z.string().trim().default('/data'),
  AGENT_WORKSPACE_ROOT: z.string().trim().default('/workspace'),
  AGENT_ALLOWED_ROOTS: z.string().trim().optional(),
  AGENT_REPORTS_DIR: z.string().trim().optional(),
  AGENT_RUNS_DIR: z.string().trim().optional(),
  AGENT_FINGERPRINT_FILE: z.string().trim().optional(),
  AGENT_MAX_FILE_SIZE_BYTES: z.coerce.number().int().positive().default(256_000),
  AGENT_MAX_LIST_FILES: z.coerce.number().int().positive().default(1_000),
  AGENT_PROMPT_TEMPLATE_VERSION: z.string().trim().default('2026-04-05'),
  AGENT_DEFAULT_PROVIDER: z.enum(PROVIDER_NAMES).optional(),
  ANTHROPIC_API_KEY: z.string().trim().optional(),
  CLAUDE_MODEL: z.string().trim().default('claude-sonnet-4-6'),
  CLAUDE_MAX_TURNS: z.coerce.number().int().positive().default(25),
  OPENAI_API_KEY: z.string().trim().optional(),
  OPENAI_MODEL: z.string().trim().default('gpt-5'),
  OPENAI_REASONING_EFFORT: z.enum(['none', 'low', 'medium', 'high']).default('medium'),
});

export interface AgentHostConfig {
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
  promptTemplateVersion: string;
  defaultProvider?: AgentProvider;
  claude: {
    apiKey?: string;
    model: string;
    maxTurns: number;
  };
  openai: {
    apiKey?: string;
    model: string;
    reasoningEffort: 'none' | 'low' | 'medium' | 'high';
  };
}

export function loadConfig(): AgentHostConfig {
  const parsed = envSchema.parse(process.env);
  const dataDir = path.resolve(parsed.AGENT_DATA_DIR);
  const workspaceRoot = path.resolve(parsed.AGENT_WORKSPACE_ROOT);
  const reportsDir = path.resolve(parsed.AGENT_REPORTS_DIR ?? path.join(dataDir, 'reports'));
  const runsDir = path.resolve(parsed.AGENT_RUNS_DIR ?? path.join(dataDir, 'runs'));
  const fingerprintFile = path.resolve(parsed.AGENT_FINGERPRINT_FILE ?? path.join(dataDir, 'fingerprints.json'));
  const allowedRoots = (parsed.AGENT_ALLOWED_ROOTS ?? workspaceRoot)
    .split(',')
    .map((root) => root.trim())
    .filter(Boolean)
    .map((root) => path.resolve(root));

  return {
    host: parsed.AGENT_HOST,
    port: parsed.AGENT_PORT,
    dataDir,
    workspaceRoot,
    reportsDir,
    runsDir,
    fingerprintFile,
    allowedRoots,
    maxFileSizeBytes: parsed.AGENT_MAX_FILE_SIZE_BYTES,
    maxListFiles: parsed.AGENT_MAX_LIST_FILES,
    promptTemplateVersion: parsed.AGENT_PROMPT_TEMPLATE_VERSION,
    defaultProvider: parsed.AGENT_DEFAULT_PROVIDER,
    claude: {
      apiKey: parsed.ANTHROPIC_API_KEY,
      model: parsed.CLAUDE_MODEL,
      maxTurns: parsed.CLAUDE_MAX_TURNS,
    },
    openai: {
      apiKey: parsed.OPENAI_API_KEY,
      model: parsed.OPENAI_MODEL,
      reasoningEffort: parsed.OPENAI_REASONING_EFFORT,
    },
  };
}
