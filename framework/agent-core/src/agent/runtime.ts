import { promises as fs } from 'node:fs';
import path from 'node:path';

import { ensureDir, hashFile, hashString, sanitizeFileName, toRelativePath } from '../lib/fs.js';
import type { RunRequestPayload } from '../types.js';

export interface AgentRuntimeConfig {
  workspaceRoot: string;
  allowedRoots: string[];
  reportsDir: string;
  fingerprintFile: string;
  maxFileSizeBytes: number;
  maxListFiles: number;
  promptTemplateVersion: string;
}

export interface FileFingerprint {
  relativePath: string;
  absolutePath: string;
  fileMd5: string;
}

export function buildRuleSetMd5(input: {
  role: string;
  env: string;
  skills: string;
  prompt: string;
  promptTemplateVersion: string;
}): string {
  return hashString(
    JSON.stringify(
      {
        role: input.role,
        env: input.env,
        skills: input.skills,
        prompt: input.prompt,
        promptTemplateVersion: input.promptTemplateVersion,
      },
      null,
      2,
    ),
  );
}

export async function resolveTargetFiles(
  request: RunRequestPayload,
  config: Pick<AgentRuntimeConfig, 'workspaceRoot' | 'allowedRoots' | 'maxFileSizeBytes'>,
): Promise<FileFingerprint[]> {
  const paths = Array.isArray(request.context?.paths)
    ? request.context.paths.filter((value): value is string => typeof value === 'string')
    : [];

  const uniquePaths = [...new Set(paths)];
  const fingerprints: FileFingerprint[] = [];

  for (const rawPath of uniquePaths) {
    const absolutePath = path.isAbsolute(rawPath) ? rawPath : path.join(config.workspaceRoot, rawPath);
    const normalized = path.resolve(absolutePath);

    const isAllowed = config.allowedRoots.some((root) => {
      const relative = path.relative(root, normalized);
      return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
    });

    if (!isAllowed) {
      continue;
    }

    const stat = await fs.stat(normalized);
    if (!stat.isFile() || stat.size > config.maxFileSizeBytes) {
      continue;
    }

    fingerprints.push({
      absolutePath: normalized,
      relativePath: toRelativePath(config.workspaceRoot, normalized),
      fileMd5: await hashFile(normalized),
    });
  }

  return fingerprints;
}

export function stringifyFinalOutput(finalOutput: unknown): string {
  if (typeof finalOutput === 'string') {
    return finalOutput;
  }

  if (finalOutput === undefined) {
    return '';
  }

  return JSON.stringify(finalOutput, null, 2);
}

export async function writeFinalReport(input: {
  runId: string;
  role: string;
  reportsDir: string;
  finalOutput: string;
}): Promise<{ reportPath: string; reportName: string }> {
  await ensureDir(input.reportsDir);
  const reportName = sanitizeFileName(`${input.role}-${input.runId}.md`);
  const reportPath = path.join(input.reportsDir, reportName);
  await fs.writeFile(reportPath, input.finalOutput || '# Empty report\n', 'utf8');
  return { reportPath, reportName };
}
