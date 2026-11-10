# Xtream Codes

Enable Live, then open Settings → Live broadcasting → Xtream Codes. Add the server URL (including its port if supplied), username and password from your provider. Multiple connections are supported. Check account shows provider status, expiry and active/max connections. Edit a connection to enable or disable it; blank credential fields preserve saved values.

Users with Live access can open Live → Xtream Codes to select a provider and browse Live TV, Movies or Series. Categories, title search, pagination, series episodes and a short live programme guide are supported. Refresh reloads the current provider's categories and catalogue. Provider connection limits still apply.

Playback uses HLS for live channels and the provider's original container for movies and episodes. Browser codec support is required; this integration does not transcode provider streams. Catch-up TV, downloading, library imports, watch-progress synchronization and full XMLTV guide ingestion are not included.

## Access and transport

- Connection administration requires `iptv_manage`; shared catalogues and playback require an authenticated account with Live access. Restricted parental profiles cannot browse or play provider content.
- Credentials are stored in the server-side repository document, following existing provider storage conventions. They are not returned by account-list or catalogue endpoints. Repository backups must be treated as sensitive.
- Playback URLs, HLS child playlists, segments and encryption keys use opaque six-hour tickets bound to the viewer and connection configuration. Disabling, deleting or editing a connection invalidates its existing tickets.
- Upstream requests allow only public HTTP(S) destinations, pin resolved addresses, validate redirects independently, verify TLS, bound API/playlist response sizes and suppress provider error bodies.
- Catalogues are cached for five minutes. Credentials and configuration participate in cache keys so edited accounts cannot reuse previous account results.

## Delivery and verification

All runtime changes are under `Application/`, covered by the built-in updater. The new repository document is created on the first save; no manual database migration or new dependency is required beyond PHP cURL, already used by existing providers. App and optional worker version labels advance together to 1.5.38; worker protocol is unchanged, so either update order remains compatible. Removing the app change leaves the saved connection document intact.

JavaScript and PHP parser syntax checks are available without a running server. End-to-end login, browsing and playback require a configured Vaultcast database and a real provider account; they have not been verified in this checkout. Functional test suites were not run, per repository instructions.
