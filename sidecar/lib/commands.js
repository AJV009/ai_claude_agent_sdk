import fs from 'fs';
import path from 'path';
import os from 'os';

const BUILTIN_COMMANDS = [
  { name: 'compact', description: 'Compact conversation history', hasArgs: false },
  { name: 'clear', description: 'Clear conversation and start fresh', hasArgs: false },
  { name: 'commit', description: 'Commit changes with a generated message', hasArgs: false },
  { name: 'context', description: 'Add file or URL context', hasArgs: true },
  { name: 'cost', description: 'Show token usage and cost', hasArgs: false },
  { name: 'help', description: 'Show available commands', hasArgs: false },
  { name: 'init', description: 'Initialize project CLAUDE.md', hasArgs: false },
  { name: 'plan', description: 'Enter plan mode for complex tasks', hasArgs: true },
  { name: 'review', description: 'Review code changes', hasArgs: false },
];

function scanDir(dir, category, commands) {
  try {
    const files = fs.readdirSync(dir).filter(f => f.endsWith('.md'));
    for (const file of files) {
      const name = path.basename(file, '.md');
      const content = fs.readFileSync(path.join(dir, file), 'utf8');
      const firstLine = content.split('\n')[0] || '';
      const description = firstLine.replace(/^#\s*/, '').trim() || name;
      const hasArgs = content.includes('$ARGUMENTS');
      commands.push({ name, description, category, hasArgs });
    }
  } catch (_) {
    // Directory may not exist.
  }
}

export function discoverCommands(workingDir) {
  const commands = BUILTIN_COMMANDS.map(cmd => ({
    ...cmd,
    category: 'builtin',
  }));

  scanDir(path.join(workingDir, '.claude', 'commands'), 'project', commands);
  scanDir(path.join(os.homedir(), '.claude', 'commands'), 'user', commands);

  return commands;
}
