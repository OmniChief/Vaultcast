'use strict';

const { readFile, stat } = require('fs').promises;
const { join } = require('path');

function readLiteral(source) {
  // Existing installers write a PHP return literal with var_export. Read its data without executing it.
  const tokens = /\s+|\/\/[^\r\n]*|#[^\r\n]*|\/\*[\s\S]*?\*\/|<\?php\b|\?>|=>|'(?:\\[\s\S]|[^'\\])*'|[a-z_][a-z_0-9]*|-?\d+|[()[\],;]/giy;
  let next;
  function advance() {
    let match;
    do {
      const offset = tokens.lastIndex;
      if (offset === source.length) { next = null; return; }
      match = tokens.exec(source);
      if (!match) throw new Error('Unsupported installation configuration');
    } while (/^(?:\s|\/\/|#|\/\*)/.test(match[0]));
    next = match[0];
  }
  function take(value) {
    if (next?.toLowerCase() !== value) throw new Error('Invalid installation configuration');
    advance();
  }
  function literal(depth) {
    if (depth > 12) throw new Error('Installation configuration is nested too deeply');
    const value = next;
    advance();
    const lower = value?.toLowerCase();
    if (value?.startsWith("'")) return value.slice(1, -1).replace(/\\(['\\])/g, '$1');
    if (/^-?\d+$/.test(value ?? '')) return Number(value);
    if (lower === 'true' || lower === 'false') return lower === 'true';
    if (lower === 'null') return null;
    const close = lower === 'array' ? ')' : (value === '[' ? ']' : null);
    if (!close) throw new Error('Unsupported installation configuration value');
    if (lower === 'array') take('(');
    const entries = Object.create(null);
    while (next !== close) {
      const key = literal(depth + 1);
      if (typeof key !== 'string' && typeof key !== 'number') throw new Error('Invalid configuration key');
      take('=>');
      entries[key] = literal(depth + 1);
      if (next === close) break;
      take(',');
    }
    take(close);
    return entries;
  }
  advance();
  take('<?php');
  take('return');
  const config = literal(0);
  take(';');
  if (next === '?>') advance();
  if (next !== null) throw new Error('Invalid installation configuration');
  return config;
}

module.exports = async function installationConfig(root) {
  let path = join(root, 'config.php');
  try { await stat(path); }
  catch (error) {
    if (error.code !== 'ENOENT') throw error;
    path = join(root, 'data/config.php');
  }
  if ((await stat(path)).size > 65536) throw new Error('Installation configuration exceeds size limit');
  const config = readLiteral((await readFile(path, 'utf8')).replace(/^\uFEFF/, ''));
  const db = config?.database;
  const port = Number(db?.port);
  if (!db || typeof db.host !== 'string' || !db.host || /[;\x00-\x1f]/.test(db.host) ||
      typeof db.name !== 'string' || !/^[a-z0-9_]{1,64}$/i.test(db.name) ||
      !Number.isInteger(port) || port < 1 || port > 65535 ||
      typeof db.user !== 'string' || typeof db.password !== 'string' ||
      (config.base_path ?? '/') !== '/') throw new Error('Unsupported database configuration');
  return { host: db.host, database: db.name, user: db.user, password: db.password, port };
};
