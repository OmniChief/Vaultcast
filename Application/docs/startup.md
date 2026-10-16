# First-response pages

The HTML response contains a real signed-in home page or a native sign-in form.
There is no boot logo or empty app container. Home navigation, title cards and
artwork image URLs are present before JavaScript downloads or executes. The form
posts to the same page and redirects after successful authentication; it does not
wait for an AJAX home request before completing sign-in.

Startup checks the current session and access state before including catalogue
data. It prefers the viewer's revision-valid home cache, then the shared library
cache. If both are absent, SQL returns up to eight real cards per section. TV
cards use grouped catalogue records rather than full summaries or media-folder
walks. This cold fallback does not perform artwork discovery or probes and does
not include private media paths. Database and network latency still affect HTML
response time.

JavaScript enhances the existing home DOM without replacing it at startup. An
incomplete personal response is refreshed in the background; Continue Watching
is updated separately so the page, search form and current scroll remain in place.
Navigation and playback use the existing application handlers after enhancement.
Detail routes still use their existing API-driven views, with the initial page
remaining visible while their route resolves. This change is server rendering of
the home/sign-in entry point, not a rewrite of every detail or settings screen.

Cache expiry remains action/revision based. Application/VERSION is unchanged.
No tests or syntax checks were run for this change, as requested.
