# Page startup

The initial HTML contains a server-verified account snapshot and, when available,
that account's revision-valid cached home response. The browser consumes this once
and follows the existing route logic. A warm visit needs no `/me` or `/home` request
before rendering. Account changes discard the supplied home response.

Home responses have no time-based expiry. Catalogue, progress, settings and
browse-epoch revisions invalidate them immediately. Each viewer has one replaceable
cache entry, so revisions do not accumulate permanent files. Cache keys are
captured before building the response so a concurrent edit cannot label old data
with a newer revision. The cache remains disposable and outside the browser.

The HTML response stays `no-store`. Startup checks the current session and access
expiry before reading cached home data; it never embeds a password hash or session
token. Guest access follows the existing guest-browsing setting. Conversion badges
continue to update separately and do not block rendering.

Cold or invalidated caches still require `/home`; the HTML request never builds
the catalogue. Deep links can still need their own detail requests. This change
removes the startup request waterfall for warm visits, not database or network
latency. Measure document response time and cold `/home` time on the deployed
server before attributing any remaining delay to the browser.

Regression checks: `node Application/tests/startup_test.js` and
`php Application/tests/startup_access_test.php`. Check warm/cold visits, expiry,
deleted accounts, guest-to-member login, and direct movie/show links on deployment.
