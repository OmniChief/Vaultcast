# Testing the performance branch

The target is useful requested-page content and above-the-fold artwork painted
within one second, including direct links. This branch addresses measured causes;
it does not claim every route already meets that target on every connection.

The existing architecture stays in place: PHP serves the API and CLI maintenance,
MariaDB stores catalogue/accounts/progress, binary artwork and disposable response
caches remain files, and Node.js serves the multiplayer relay. Navigation still
uses a small identity-scoped in-memory response cache; ordinary browsing never
starts remote artwork discovery or automatic media probes. Explicit playback
retains its codec/audio checks.

## Changes

- Shared show/facet/recent summaries are revision-managed instead of expiring
  every hour. Catalogue, metadata and settings changes invalidate them. Recent
  rows also refresh on the UTC day boundary so time-based labels do not freeze.
  Search/filter response caches retain a ten-minute TTL.
- Recently Updated no longer checks a timestamp it never wrote. It resolves
  slugs, facts and public metadata only after choosing the displayed cards.
- Scan, media-facts and metadata maintenance prepare browse views before the
  next visit. Metadata preparation adopts local artwork (including games) into
  the poster cache and creates missing WebP variants in the CLI worker.
- Deep links render a small route shell instead of starting unrelated Home
  artwork downloads. Home retains real first-response cards.
- Artwork responses use a bounded cache lifetime; browser Cache API entries
  refresh after an hour, allowing prepared thumbnails to replace cached originals.
  A new artwork URL/cache namespace bypasses the old immutable original responses.
- `Server-Timing` reports app duration, database read/decode duration, JSON
  serialization, file-cache read/hit counts and derived-view hit/miss counts.
  These values contain no query text, paths, account data or tokens. Database
  timing covers the repository read helpers, not every direct PDO call.
- Shipped CSS/JavaScript URLs use an application asset responder before database
  and authentication bootstrap. It automatically caches gzip bytes by content
  hash and supplies versioned immutable caching, ETags and Accept-Encoding
  negotiation. HTML and JSON are compressed too. Existing nginx configurations
  require no changes, privileged access, config reload or extra installation.
  Image/media/range bodies are unaffected. Service-worker asset caching includes
  these URLs while continuing to exclude authenticated HTML/API responses.

## After installing

1. The first application request after installation automatically queues
   **Prepare artwork and synopses** in the existing maintenance worker. Existing
   local artwork is optimized even when remote posters are disabled. Settings'
   background tasks show `artwork_prepared`/`artwork_failed`;
   unavailable encoders and unreadable files are logged. This work may take time
   on a large library. It does not run inside image or page requests.
   Run **Probe new media codecs and store show guides** to prepare audio/codec
   facts too. Scans queue both jobs through the existing CLI maintenance worker;
   administrators can also run `php src/Console.php metadata` and
   `php src/Console.php facts`.
2. Compression and static caching take effect through the ordinary update alone.
   There is no nginx configuration step. A failed maintenance-worker launch is
   logged and retried after five minutes; successful queues are recorded under
   `data/runtime/performance-v2.json` to avoid duplicate jobs. Existing TLS,
   HTTP protocol, OPcache and worker-pool settings remain under host management;
   this branch does not depend on changing them.

## Measurement

Use Edge Network and Performance with no throttling, then with a representative
mobile/network profile. Check that JS/CSS/JSON return `Content-Encoding: gzip`,
versioned assets have Cache-Control, and compressed bytes are reused on reload.
Inspect `Server-Timing` to separate application time from transfer/connection
queueing. Keep private HAR/trace files local.

Measure Home, Films, TV, Anime, Collections, Games, Live, movie/show/season detail,
search, filters and Settings, both as direct loads and in-app transitions. Repeat
at least five times with cold/warm browser caches, after scan/metadata changes,
and from two simultaneous browsers. Report median and p95 of requested-route
paint/readiness, not just DOMContentLoaded or a cached placeholder. Rotating hero
images can introduce a later LCP candidate; inspect the trace and first useful
paint separately. APIs should normally take less than 200 ms to leave room for
transfer and rendering within a one-second page budget.

The automated 6,000-episode fixture checks bounded projection and zero episode
reads on warm Recently Updated responses. It uses fake storage for the catalogue
and real file caches; its elapsed time is not a production-server benchmark.
The artwork test performs actual GD WebP resizing of a local game sidecar.

## Artwork preparation and Settings consistency (issue #51)

Preparation now uses a revision-cached inventory from the current catalogue:
one entry per movie/game, show and season. It never resolves shared TV art once
per episode. A 5,000-episode regression fixture produces three TV artwork entries.
Remote poster and synopsis work each have a five-second budget per entry;
Matching's TVMaze fallback observes the same deadline. Each maintenance pass
allows about two minutes of remote work and two minutes of encoding, plus the
current entry and local inventory/page preparation. Remaining work queues another
pass automatically, releasing the maintenance lock between passes. Progress and
continuation IDs are visible in Background tasks.

Attempts, including provider misses, persist for 24 hours against the target's
signature. Changed catalogue entries can retry immediately. Failed image encodes
also have a 24-hour cooldown against source size/mtime, so one corrupt file does
not make continuations loop forever. Manual artwork optimisation remains available
for immediate retries. Missing artwork is reported separately from worker errors;
preparation cannot create an image a provider does not have. Cancelling stops the
current pass before it queues a continuation; rerunning resumes saved work.

Artwork Find all now scans library entries, imports local sidecars without network
requests and reports ready, needing WebP and missing-artwork counts separately.
Movies include legacy Disney libraries. Multiple scope selections are filtered on
the server so totals match the selected inventory. The summary explicitly counts
cached originals (which may include removed-library artwork), rather than claiming
to be a movie count. Summary reads no longer query item metadata per cached poster.
Queue polling preserves unsaved form controls, save confirmation follows the server
response, and stopped scans cannot replace a newer scan's results.

The conversion finder now uses the same stored media facts as library cards,
including Disney movies, instead of running up to 40 live ffprobe calls inside an
HTTP request. Until facts exist it uses the same extension fallback as the library;
run the codec background task to obtain accurate codec verdicts.

Validation includes inventory/count reconciliation, revision invalidation, local
sidecar discovery, bounded/resumed work, provider-miss deduplication, cancellation,
actual WebP resizing, conversion parity without ffprobe, and artwork Settings races.
These are local regression checks; production completion time and page latency
still need verification after installing the branch on the server.

Fix Match also removes obsolete full/thumb WebP files together with replaced movie,
show and season originals. Previously those variants could keep serving the old
image after a successful rematch. Show pin changes bypass the preparation cooldown.
The v2 setup stamp queues the corrected preparation once for earlier branch installs.
