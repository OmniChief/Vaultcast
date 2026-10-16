# Browsing performance

Page navigation now reads cached artwork and synopsis data. It no longer requests
remote artwork discovery or launches an automatic movie audio probe. Missing artwork
keeps its title placeholder until prepared; playback still performs the checks it needs.

After upgrading an existing library, run these once in **Settings → Maintenance →
Background tasks**:

- **Probe new media codecs and store show guides** prepares audio-track information
  in MariaDB as well as codec facts.
- **Prepare artwork and synopses** fills the existing artwork and metadata caches,
  then warms show summaries and movie facets. Poster fetching must be enabled.

Successful library scans queue both jobs through the existing PHP CLI maintenance
worker. Browsing does not queue them. They can also be run explicitly through
`php src/Console.php facts` and `php src/Console.php metadata`. Remote providers may
have missing results or rate limits; these jobs can take time on large libraries.

MariaDB continues to hold catalogue rows, accounts, sessions, watch progress,
metadata pins and maintenance jobs. Media facts are fetched by primary key in
batches for the requested cards, rather than reading the entire facts table.
Show details use the section/show index to narrow episode reads. Binary artwork
and disposable response caches remain files; they do not benefit from being copied
into relational tables. Node.js remains the multiplayer relay; PHP handles API
requests and its existing CLI workers handle scanning and enrichment.

The browser keeps up to 40 read-only navigation responses for 15 seconds in memory,
separated by user identity. Concurrent identical reads share one request. Writes,
login/logout and access failures invalidate the cache. There is no persistent
browse-response cache. Versioned static assets are served from the service worker
cache after their first successful fetch. Cold content uses reserved placeholders
instead of repeated “Loading” labels; real transfers and explicit actions still
report progress. Installer screens are unchanged.

## Webapp review

No PHP/MariaDB/nginx stack was installed or started to validate this change. JavaScript
syntax, navigation-cache regression checks and the existing route checks were run.
PHP execution, database query plans and real response times still need validation
on the deployed webapp.

1. Compare first and repeat visits to Home, movie and TV sections, filters, show
   details and movie details. Inspect request timings with a representative library.
2. Confirm movie navigation sends no automatic `/probe` or `/synopsis` requests,
   and ordinary card poster URLs use `fetch=0`. Start playback and check audio selection.
3. Run the two background tasks and confirm previously missing artwork, synopses,
   audio tracks and codec badges appear after refresh.
4. Change filters quickly, use Back, change accounts, and perform metadata edits;
   check that older responses do not replace the current section or survive writes.
5. Clear disposable caches, reload and confirm completed migrations do not repeat
   the library permission pass. Test Live, downloads and multiplayer for regressions.

This removes specific avoidable work; it does not promise zero network latency.
Cold TV summaries, filtered catalogue searches, Live guide generation, provider
calls from explicit admin actions and playback preparation remain candidates for
measurement on the actual server before further changes.
