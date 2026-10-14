# Publishing a Vaultcast update

The in-app updater (**Settings → Maintenance → Check for updates**) compares the
installed application files with the `main` branch of `OmniChief/Vaultcast` on
GitHub. There is **no build step, no release package and no checksum file**.
Publishing an update is just pushing to `main`.

## How the updater decides what to change

1. One call to the GitHub Git Trees API (`GET /repos/OmniChief/Vaultcast/git/trees/main?recursive=1`)
   lists every file's path and its git blob SHA.
2. Local files under the allowlist are hashed the same way Git does
   (`sha1("blob <len>\0" + content)`). A file whose local content matches the
   remote blob SHA is left alone. A Windows CRLF copy of an LF file still
   counts as a match, so git autocrlf or editors that rewrite endings don't
   flag every file.
3. Files on the allowlist that differ, are new, or were removed upstream are
   listed. **Update now** downloads only those files (from
   `raw.githubusercontent.com`, falling back to the blobs API) and verifies
   each download's blob SHA against the tree. That verification is the
   integrity check.
4. Verified files are written atomically (temp file + rename, with Windows
   lock retries) under maintenance mode. Each replaced file is backed up
   first; any failure rolls the whole update back. `VERSION` is written last.

## What an update may replace

Only these paths, relative to the application root (the folder that holds
`index.php` and `VERSION` — the Windows installer and the Linux script both
install the contents of `Application/` there):

- Root files: `index.php`, `router.php`, `sw.js`, `manifest.json`, `.htaccess`,
  `VERSION`, and the repository-root `LICENSE` and `NOTICE` (copied into the
  app root).
- Directories: `src/`, `assets/`, `docs/`, `deploy/`, `netplay/` (without
  `node_modules`), `tests/`.
- Files removed upstream are deleted only inside `src/`, `assets/`, `docs/`
  and `tests/`. Local copies under `deploy/` (for example an adapted nginx
  config) and anything under `netplay/node_modules` are left alone.

Never touched: `config.php`, `data/` (settings, caches, uploads, Windows
runtimes under `data/runtime/`, Transmission), the database, media folders,
`node_modules`, `.user.ini`.

## Steps to publish

1. **Bump the version** when the change is a release users should notice.
   Edit `Application/VERSION` so it holds just `X.Y.Z`. This file is the only
   source of truth for the displayed version and for the version-keyed
   database migrations. A push that only changes other files still updates
   those files; the installed version number stays the same until `VERSION`
   itself changes.
2. **Push to `main`.** That is the whole publish step. The updater starts
   offering the new files as soon as GitHub's tree reflects the push (usually
   within a few seconds; the raw CDN can lag for a minute, and the updater
   falls back to the blobs API when that happens).
3. Optionally keep `Application/netplay/package.json`'s `"version"` in step.
   If its dependencies change, users who run the multiplayer relay need to
   run `npm ci` in `netplay/` (the Windows installer's Repair does this).

There is nothing to attach, tag or checksum. Do not create a GitHub Release
for the updater; it never reads releases.

## Private repository and rate limits

While the repository is private, the GitHub API returns 404 to anonymous
requests. Either make the repository public, or give each installation a
read-only fine-grained token (Contents: read) in `data/config.php` as
`'update_token' => '...'`, or in the `VAULTCAST_UPDATE_TOKEN` environment
variable.

Unauthenticated calls share GitHub's 60-per-hour limit. The updater:

- sends `If-None-Match` with the previous tree's ETag, so a 304 costs nothing;
- stores the last successful tree for a few hours and uses it when the limit
  is exhausted, with a clear note;
- explains the limit and how a token raises it.

## Database migrations

Migrations still run through the idempotent startup pattern
(`Migrations::runIfNeeded` under its MariaDB lock). The stamp file includes
the app version and is cleared after an update, so a VERSION bump re-runs any
new migrations once on the next request.
