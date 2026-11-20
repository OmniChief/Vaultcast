'use strict';

const { readFile, writeFile, rename, unlink } = require('fs').promises;
const { randomBytes } = require('crypto');
const { now } = require('./account-data');

module.exports = class ProviderCookies {
  constructor(path) { this.cookiePath = path; this.cookies = []; }

  async loadJar() {
    if (!this.cookiePath) return;
    const text = await readFile(this.cookiePath, 'utf8').catch(() => '');
    for (let line of text.split(/\r?\n/)) {
      if (line.startsWith('#HttpOnly_')) line = line.slice(10);
      else if (line.startsWith('#')) continue;
      const [domain, sub, path, secure, expires, name, value] = line.split('\t');
      if (name && value != null) {
        const host = domain.replace(/^\./, '').toLowerCase();
        this.cookies = this.cookies.filter(
          (cookie) => !(cookie.domain === host && cookie.path === path && cookie.name === name),
        );
        this.cookies.push({
          domain: host,
          sub: sub === 'TRUE',
          path,
          secure: secure === 'TRUE',
          expires: Number(expires),
          name,
          value,
        });
      }
    }
  }

  cookieHeader(url, mag = false) {
    const host = url.hostname.toLowerCase();
    const cookies = this.cookies
      .filter(
        (cookie) =>
          (!mag || !['mac', 'stb_lang', 'timezone'].includes(cookie.name)) &&
          (!cookie.expires || cookie.expires > now()) &&
          (host === cookie.domain || (cookie.sub && host.endsWith('.' + cookie.domain))) &&
          (url.pathname === cookie.path ||
            url.pathname.startsWith(cookie.path.endsWith('/') ? cookie.path : cookie.path + '/')) &&
          (!cookie.secure || url.protocol === 'https:'),
      )
      .map((cookie) => cookie.name + '=' + cookie.value);
    if (mag)
      cookies.push('mac=' + encodeURIComponent(this.feed.mac), 'stb_lang=en', 'timezone=Europe/London');
    return cookies.join('; ');
  }

  async acceptCookies(url, headers) {
    for (const header of headers['set-cookie'] ?? []) {
      const [pair, ...attributes] = header.split(';');
      const index = pair.indexOf('=');
      if (index < 1) continue;
      const name = pair.slice(0, index).trim(),
        value = pair.slice(index + 1).trim();
      if (!/^[!#$%&'*+.^_`|~\w-]+$/.test(name) || /[\x00-\x20\x7f;]/.test(value)) continue;
      const attrs = Object.fromEntries(
        attributes.map((part) => {
          const split = part.indexOf('=');
          return split < 0
            ? [part.trim().toLowerCase(), true]
            : [part.slice(0, split).trim().toLowerCase(), part.slice(split + 1).trim()];
        }),
      );
      const domain = String(attrs.domain ?? url.hostname)
        .replace(/^\./, '')
        .toLowerCase();
      if (domain !== url.hostname && (!domain.includes('.') || !url.hostname.endsWith('.' + domain)))
        continue;
      const path =
        typeof attrs.path === 'string' && attrs.path.startsWith('/')
          ? attrs.path
          : url.pathname.slice(0, url.pathname.lastIndexOf('/') + 1);
      const expires =
        typeof attrs['max-age'] === 'string' && /^-?\d+$/.test(attrs['max-age'])
          ? now() + Number(attrs['max-age'])
          : attrs.expires
            ? Math.floor(Date.parse(attrs.expires) / 1000) || 0
            : 0;
      this.cookies = this.cookies.filter(
        (cookie) => !(cookie.domain === domain && cookie.path === path && cookie.name === name),
      );
      this.cookies.push({
        domain,
        sub: Boolean(attrs.domain),
        path,
        secure: Boolean(attrs.secure),
        expires,
        name,
        value,
      });
    }
    if (this.cookiePath) {
      const text =
        '# Netscape HTTP Cookie File\n' +
        this.cookies
          .filter((cookie) => !cookie.expires || cookie.expires > now())
          .map((cookie) =>
            [
              cookie.domain,
              cookie.sub ? 'TRUE' : 'FALSE',
              cookie.path,
              cookie.secure ? 'TRUE' : 'FALSE',
              cookie.expires,
              cookie.name,
              cookie.value,
            ].join('\t'),
          )
          .join('\n') +
        '\n';
      const temporary = this.cookiePath + '.' + randomBytes(8).toString('hex') + '.tmp';
      try {
        await writeFile(temporary, text, { mode: 0o600, flag: 'wx' });
        await rename(temporary, this.cookiePath);
      } finally {
        await unlink(temporary).catch(() => {});
      }
    }
  }
};
