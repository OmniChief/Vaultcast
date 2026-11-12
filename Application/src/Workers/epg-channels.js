'use strict';

const { createReadStream, promises: fs } = require('fs');
const { join } = require('path');
const { TextDecoder } = require('util');
const sax = require('./vendor/sax');

const endOfHeader = Symbol('end of XMLTV header');
const localName = name => name.slice(name.lastIndexOf(':') + 1);

async function scanHeader(path, visit, rootOnly = false) {
  const parser = sax.parser(true, { strictEntities: true, position: false });
  const decoder = new TextDecoder('utf-8', { fatal: true });
  let depth = 0;
  let foundRoot = false;
  let channel = null;
  let displayNameDepth = 0;
  let bytes = 0;
  const limit = (rootOnly ? 1 : 16) * 1024 * 1024;

  parser.onerror = error => { throw error; };
  parser.onprocessinginstruction = ({ name, body }) => {
    const encoding = /^xml$/i.test(name) && body.match(/\bencoding\s*=\s*['"]([^'"]+)['"]/i)?.[1];
    if (encoding && !/^(?:utf-8|us-ascii)$/i.test(encoding)) throw new Error('Unsupported XMLTV encoding');
  };
  parser.onopentag = tag => {
    const name = localName(tag.name);
    if (++depth > 64) throw new Error('XMLTV header is nested too deeply');
    if (depth === 1) {
      if (name !== 'tv') throw new Error('Expected an XMLTV root');
      foundRoot = true;
      if (rootOnly) throw endOfHeader;
    } else if (name === 'programme') {
      throw endOfHeader;
    } else if (name === 'channel' && channel === null) {
      const id = tag.attributes.id || '';
      channel = { depth, id, name: null, icon: null };
    } else if (channel && depth === channel.depth + 1) {
      if (name === 'display-name' && channel.name === null) {
        channel.name = '';
        displayNameDepth = depth;
      } else if (name === 'icon' && channel.icon === null) {
        channel.icon = tag.attributes.src || '';
      }
    }
  };
  parser.ontext = parser.oncdata = text => {
    if (displayNameDepth === depth && channel) channel.name = (channel.name + text).slice(0, 4096);
  };
  parser.onclosetag = () => {
    if (depth === displayNameDepth) displayNameDepth = 0;
    if (channel && depth === channel.depth) {
      if (channel.id && visit(channel) === false) throw endOfHeader;
      channel = null;
    }
    depth--;
  };

  try {
    for await (const chunk of createReadStream(path, { highWaterMark: 16384 })) {
      bytes += chunk.length;
      if (bytes > limit) throw new Error('XMLTV header exceeds size limit');
      // Decode only the XMLTV header. Guide entries belong to the programme indexer.
      parser.write(decoder.decode(chunk, { stream: true }));
    }
    parser.write(decoder.decode()).close();
    if (!foundRoot) throw new Error('Missing XMLTV root');
  } catch (error) {
    if (error !== endOfHeader) throw error;
  }
}

async function main() {
  const [mode, source, target] = process.argv.slice(2);
  const entries = new Map();
  if (mode === '--header') {
    await scanHeader(source, channel => {
      const id = channel.id.trim();
      if (!id || Buffer.byteLength(id) > 160 || entries.has(id)) return;
      entries.set(id, { id, name: (channel.name || '').trim().slice(0, 320), icon: (channel.icon || '').slice(0, 501) });
      return entries.size < 1000;
    });
    process.stdout.write(JSON.stringify([...entries.values()]));
    return;
  }
  if (mode !== '--catalogue') throw new Error('Unknown XMLTV indexing operation');
  let catalogueBytes = 0;
  const names = (await fs.readdir(source)).filter(name => !name.startsWith('.') && name.endsWith('.xml')).sort();
  for (const name of names) {
    await scanHeader(join(source, name), channel => {
      if (entries.has(channel.id)) return;
      const name = channel.name ?? channel.id;
      catalogueBytes += Buffer.byteLength(channel.id) + Buffer.byteLength(name) + 64;
      if (catalogueBytes > 16 * 1024 * 1024) throw new Error('XMLTV channel catalogue exceeds size limit');
      entries.set(channel.id, { id: channel.id, name });
      return entries.size < 100000;
    });
    if (entries.size >= 100000) break;
  }
  await fs.writeFile(target, JSON.stringify([...entries.values()]), { flag: 'wx', mode: 0o600 });
  process.stdout.write('indexed\n');
}

module.exports.validate = path => scanHeader(path, () => {}, true);
if (require.main === module) main().catch(() => { process.exitCode = 1; });
