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
   `data/runtime/performance-v1.json` to avoid duplicate jobs. Existing TLS,
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
