# Contributing to Vaultcast

## Technology rules
- Use **plain PHP, HTML, CSS and JavaScript** only. The app has to run unchanged on
  Windows, Linux x64/arm64 and NAS devices with PHP 7.4+ and MariaDB.
- **Don't add dependencies that need native installs or compilation.** That rules
  out PHP extensions beyond the standard bundled set (pdo_mysql, mbstring, curl,
  zip/phar, json), Composer packages with native parts, node-gyp modules,
  shell-outs to PowerShell or bash, and Docker.
- Front-end code is shipped as-is, with no build step and no bundler.
- The multiplayer relay (`Application/netplay`) may use only pure-JavaScript npm
  packages. Check every new package's licence and add it to `NOTICE`.
- Keep the installers (`Installers/`) separate from the app. Changes to them go
  through their maintainer.

## Versioning
- `Application/VERSION` is the **only** source of truth for the app version.
  PHP reads it as `STREAM_VERSION`. The updater compares installed files with
  `main` on GitHub by git blob SHA; bump `VERSION` when a change should show as
  a new version. Don't hard-code version numbers anywhere else.
- To publish an update, push to `main`. Details:
  [docs/releasing.md](Application/docs/releasing.md).

## Before opening a pull request
```
find Application tools -name '*.php' -not -path '*/node_modules/*' -exec php -l {} \;
for f in Application/assets/js/*.js; do node --check "$f"; done
for t in Application/tests/*_test.php; do php "$t"; done   # each prints "all passed"
node Application/tests/routes_test.js
```

## Licence
By contributing, you agree that your contribution is licensed under the
[PolyForm Noncommercial License 1.0.0](LICENSE) together with the rest of Vaultcast.
Add any bundled third-party component to `NOTICE` along with its licence.
