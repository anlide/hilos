import { createHash, randomUUID } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';

export function hashString(value: string): string {
  return createHash('md5').update(value, 'utf8').digest('hex');
}

export async function hashFile(filePath: string): Promise<string> {
  const content = await fs.readFile(filePath);
  return createHash('md5').update(content).digest('hex');
}

export async function ensureDir(dirPath: string): Promise<void> {
  await fs.mkdir(dirPath, { recursive: true });
}

export async function fileExists(filePath: string): Promise<boolean> {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

export async function readJsonFile<T>(filePath: string, fallback: T): Promise<T> {
  if (!(await fileExists(filePath))) {
    return fallback;
  }

  const content = await fs.readFile(filePath, 'utf8');
  if (content.trim() === '') {
    return fallback;
  }

  return JSON.parse(content) as T;
}

export async function writeJsonFile(filePath: string, data: unknown): Promise<void> {
  await ensureDir(path.dirname(filePath));
  await fs.writeFile(filePath, JSON.stringify(data, null, 2) + '\n', 'utf8');
}

export function ensureInsideAllowedRoots(targetPath: string, allowedRoots: string[]): string {
  const normalizedTarget = path.resolve(targetPath);
  const normalizedRoots = allowedRoots.map((root) => path.resolve(root));

  const isAllowed = normalizedRoots.some((root) => {
    const relative = path.relative(root, normalizedTarget);
    return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
  });

  if (!isAllowed) {
    throw new Error(`Path is outside allowed roots: ${normalizedTarget}`);
  }

  return normalizedTarget;
}

export function toRelativePath(root: string, targetPath: string): string {
  const relative = path.relative(root, targetPath);
  return relative === '' ? '.' : relative.split(path.sep).join('/');
}

export function sanitizeFileName(fileName: string): string {
  return fileName.replace(/[^a-zA-Z0-9._-]/g, '_');
}

export function newEventId(): string {
  return randomUUID();
}