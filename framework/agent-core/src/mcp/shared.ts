import { promises as fs } from 'node:fs';
import path from 'node:path';

import { z } from 'zod';

import { ensureInsideAllowedRoots, hashFile, hashString, toRelativePath } from '../lib/fs.js';

const envSchema = z.object({
  AGENT_WORKSPACE_ROOT: z.string().trim().default('/workspace'),
  AGENT_ALLOWED_ROOTS: z.string().trim().optional(),
  AGENT_REPORTS_DIR: z.string().trim().default('/data/reports'),
  AGENT_FINGERPRINT_FILE: z.string().trim().default('/data/fingerprints.json'),
  AGENT_MAX_FILE_SIZE_BYTES: z.coerce.number().int().positive().default(256_000),
  AGENT_MAX_LIST_FILES: z.coerce.number().int().positive().default(1_000),
  AGENT_PROMPT_TEMPLATE_VERSION: z.string().trim().default('2026-04-05'),
});

export interface McpRuntimeConfig {
  workspaceRoot: string;
  allowedRoots: string[];
  reportsDir: string;
  fingerprintFile: string;
  maxFileSizeBytes: number;
  maxListFiles: number;
  promptTemplateVersion: string;
}

export function loadMcpRuntimeConfig(): McpRuntimeConfig {
  const parsed = envSchema.parse(process.env);
  const workspaceRoot = path.resolve(parsed.AGENT_WORKSPACE_ROOT);
  const allowedRoots = (parsed.AGENT_ALLOWED_ROOTS ?? workspaceRoot)
    .split(',')
    .map((root) => root.trim())
    .filter(Boolean)
    .map((root) => path.resolve(root));

  return {
    workspaceRoot,
    allowedRoots,
    reportsDir: path.resolve(parsed.AGENT_REPORTS_DIR),
    fingerprintFile: path.resolve(parsed.AGENT_FINGERPRINT_FILE),
    maxFileSizeBytes: parsed.AGENT_MAX_FILE_SIZE_BYTES,
    maxListFiles: parsed.AGENT_MAX_LIST_FILES,
    promptTemplateVersion: parsed.AGENT_PROMPT_TEMPLATE_VERSION,
  };
}

export function resolveSafeTarget(rawPath: string, config: McpRuntimeConfig): string {
  const requestedPath = path.isAbsolute(rawPath) ? rawPath : path.join(config.workspaceRoot, rawPath);
  return ensureInsideAllowedRoots(requestedPath, config.allowedRoots);
}

export async function listFilesInDirectory(rawPath: string, config: McpRuntimeConfig): Promise<string[]> {
  const resolvedDir = resolveSafeTarget(rawPath, config);
  const entries = await fs.readdir(resolvedDir, { withFileTypes: true });

  return entries
    .slice(0, config.maxListFiles)
    .filter((entry) => entry.isFile())
    .map((entry) => toRelativePath(config.workspaceRoot, path.join(resolvedDir, entry.name)))
    .sort();
}

export async function readSafeFile(rawPath: string, config: McpRuntimeConfig): Promise<string> {
  const resolvedPath = resolveSafeTarget(rawPath, config);
  const stat = await fs.stat(resolvedPath);
  if (!stat.isFile()) {
    throw new Error(`Target is not a file: ${resolvedPath}`);
  }

  if (stat.size > config.maxFileSizeBytes) {
    throw new Error(`File is too large: ${resolvedPath}`);
  }

  return fs.readFile(resolvedPath, 'utf8');
}

export async function fingerprintFiles(
  paths: string[],
  config: McpRuntimeConfig,
): Promise<
  Array<{
    relativePath: string;
    absolutePath: string;
    fileMd5: string;
    size: number;
    modifiedAt: string;
  }>
> {
  const uniquePaths = [...new Set(paths)];
  const results = [];

  for (const rawPath of uniquePaths) {
    const absolutePath = resolveSafeTarget(rawPath, config);
    const stat = await fs.stat(absolutePath);
    if (!stat.isFile()) {
      continue;
    }

    if (stat.size > config.maxFileSizeBytes) {
      throw new Error(`File is too large: ${absolutePath}`);
    }

    results.push({
      relativePath: toRelativePath(config.workspaceRoot, absolutePath),
      absolutePath,
      fileMd5: await hashFile(absolutePath),
      size: stat.size,
      modifiedAt: stat.mtime.toISOString(),
    });
  }

  return results;
}

export function fingerprintRuleInput(input: {
  role: string;
  env: string;
  skills: string;
  prompt: string;
}): { md5: string; normalized: string } {
  const normalized = JSON.stringify(
    {
      role: input.role,
      env: input.env,
      skills: input.skills.trim(),
      prompt: input.prompt.trim(),
      promptTemplateVersion: loadMcpRuntimeConfig().promptTemplateVersion,
    },
    null,
    2,
  );

  return {
    md5: hashString(normalized),
    normalized,
  };
}
