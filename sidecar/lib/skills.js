import fs from 'fs';
import path from 'path';
import os from 'os';
import yaml from 'js-yaml';

/**
 * Parse YAML frontmatter from a SKILL.md file.
 *
 * Expects the format:
 * ---
 * key: value
 * ---
 * Body content...
 */
function parseFrontmatter(content) {
  const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);
  if (!match) {
    return { meta: {}, body: content };
  }
  try {
    const meta = yaml.load(match[1]) || {};
    return { meta, body: match[2] };
  } catch (_) {
    return { meta: {}, body: content };
  }
}

/**
 * Scan a skills directory for SKILL.md files inside subdirectories.
 *
 * Looks for {dir}/{skill-name}/SKILL.md
 */
function scanSkillsDir(dir, category, skills) {
  let entries;
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch (_) {
    return;
  }

  for (const entry of entries) {
    if (!entry.isDirectory()) continue;

    const skillFile = path.join(dir, entry.name, 'SKILL.md');
    let content;
    try {
      content = fs.readFileSync(skillFile, 'utf8');
    } catch (_) {
      continue;
    }

    const { meta, body } = parseFrontmatter(content);
    const hasArgs = body.includes('$ARGUMENTS');

    skills.push({
      name: meta.name || entry.name,
      description: meta.description || '',
      category,
      hasArgs,
      userInvocable: meta['user-invocable'] !== false,
      disableModelInvocation: meta['disable-model-invocation'] === true,
      argumentHint: meta['argument-hint'] || '',
    });
  }
}

/**
 * Discover Agent Skills from .claude/skills/ directories.
 *
 * Scans project-level and user-level skill directories.
 */
export function discoverSkills(workingDir) {
  const skills = [];

  // Project-level skills.
  scanSkillsDir(path.join(workingDir, '.claude', 'skills'), 'project-skill', skills);

  // User-level skills.
  scanSkillsDir(path.join(os.homedir(), '.claude', 'skills'), 'user-skill', skills);

  return skills;
}
