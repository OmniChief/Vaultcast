# Using Vaultcast

## Your library

Open a library from the navigation menu, then browse or search for a title.
Film and television pages show artwork and available playback actions.
Music and game libraries support alphabetical browsing; games also have system
filters. Sign in to keep personal preferences and viewing progress.

The library is readable by guests. Accounts protect administration and store
personal preferences; they do not restrict all media to signed-in users.

Administrators can add folders under **Settings → Libraries → Folders & media types**.
Enable the media types you want, enter paths as the server sees them, save and scan.
Scanning reads files in place. The media-management tools can rename, move or
delete files on disk when you request those actions.

## Playback

Select a title to play it. Resume starts from your saved position when available.
Use **Audio** to select tracks exposed by the browser. The selection is remembered
for that title. Browsers without native audio-track switching play the file's
default track; Vaultcast does not extract or transcode audio during playback.

Use **Subtitles** to select available captions or turn them off. Embedded captions
appear when the browser exposes them. You can also place `.vtt` or `.srt` files
beside the video with the same base filename, for example `Film.en.srt` and
`Film.fr.vtt` for `Film.mp4`. Subtitle files can be up to 2 MB each. SRT timestamps
are converted to WebVTT as text, without FFmpeg or video conversion.

For broad
compatibility, the DirectPlay conversion tool prepares MP4/H.264/AAC files ahead
of playback. It copies compatible streams without re-encoding, converts audio
alone when possible, and uses single-thread video conversion when needed.
FFmpeg and writable destination folders are required; no live transcoding runs
during playback.

## Games and lobbies

Open **Play**, choose a system or search for a game, then launch it.
EmulatorJS loads from the selected CDN or server copy; ROMs remain on your server.
Games requiring a BIOS need the appropriate BIOS file supplied by you.

Open the lobby to host a game or join an available session as P2, P3 or P4.
Every game can be hosted. A seat does not guarantee that the game supports that
many players: unused players may only watch, and some games enable extra input
only in particular modes. A room closes when its host leaves.

The lobby paces the game to its participants. If a phone is backgrounded or cannot
keep up, the host waits rather than letting that player fall behind. Return to
the game to continue, or leave the lobby before putting the device away.

## Player files

Administrators can choose **Settings → Playback → Player files → Download to server**
to copy EmulatorJS, all its cores, and HLS.js from their pinned CDNs. PHP cURL and
internet access on the server are needed for the download. Keep the page open;
**Resume download** continues after an interruption. The download may be large
because it includes every supported emulator core.

Vaultcast switches to the server copy only after all files finish downloading.
**Use CDN** switches back without deleting the local copy. **Download again**
rebuilds it. Player files live under `data/players/` and are included when you back
up application data. Other open browser tabs should be refreshed after switching.

## Live channels

An administrator enables Live under **Settings → Integrations**.
Use its settings to build channels and playlists from library items, or add IPTV
feeds. The guide shows scheduled programming; external-player playlists can be
opened in applications such as VLC. Missing media durations can be filled using
the `durations` CLI command described in the installation guide.

## Settings

- **Your preferences:** account, display and navigation choices.
- **Libraries:** folders, scanning, collections, artwork and matching.
- **Users:** administrator-managed accounts.
- **Playback:** streaming and conversion options.
- **Integrations:** optional Live, downloads and server tools.
- **Maintenance:** application options, diagnostics, caches and advanced settings.

Desktop, mobile and TV browsers use the same interface. Touch targets and layouts
adapt to screen size. Remote navigation uses directional keys to move focus;
select a focused control with the remote's confirmation button. Emulator controls
take over while a game is active.

Optional server tools depend on your operating system and permissions. Leave them
disabled unless you have configured the corresponding server capabilities.
On Linux with systemd, set `system.services` in Advanced configuration to the
services you want to manage, for example
`[{"id":"nginx","unit":"nginx.service","label":"nginx"}]`.
Only listed services can be restarted; Vaultcast does not grant itself system permissions.

Download integrations are administrator-only. Regular users and guests see the
library's available media without download progress, fetch buttons or acquisition
controls. An administrator must enable downloads and configure the integration
before these controls appear.

## Background maintenance

Open **Settings → Maintenance → Background tasks** as an administrator to start
library scans, collection matching, completed-download imports or Live-duration
probing. The list records who requested each task and whether it is queued,
running, complete, cancelled or failed. Use **Refresh status** for an update.
Only one queued maintenance task runs at a time. **Cancel** requests a stop at the
next safe file or transaction boundary; it does not undo completed work.
Saving Live settings uses cached durations and never starts a hidden probe worker.

Video conversions and artwork batches are managed in their own settings pages;
links are available from Background tasks. Download transfers are managed in
Download queue. Nothing installs a recurring maintenance schedule automatically.
Runs started through the maintenance CLI also appear in this list and accept cancellation.
Externally configured Task Scheduler or cron schedules must be disabled or rescheduled
in those tools to prevent future runs; the application cannot discover every system schedule.

## Finding a better title match

Movie searches combine Apple iTunes and Wikipedia without a supplied API key.
Results are ranked by title and, when entered, release year. Anime searches use
MyAnimeList titles through Jikan to find alternate names in TVMaze; episode guides
still come from TVMaze. In the show picker, a TVMaze page URL, `tvmaze:123`, or
an IMDb identifier such as `tt0944947` can select a specific show. Check the title,
year and source before applying a match. Lookups are cached for an hour.

## Updating Vaultcast

Administrators can open **Settings → Maintenance → Check for updates** to see
the installed version, the version on GitHub and the list of files that differ.
Select **Update now** to install them.

The **Update branch** selector also lists available test branches. Selecting one
checks its files; **Switch to branch-name** installs it and pins future checks
to that branch. Select `main` and apply the switch to return to the standard
build, even if its version is lower or its files are identical. If a test branch
is deleted, Vaultcast keeps its installed files and pin; it offers `main` without
switching automatically. Branch switching does not reverse database migrations
made by test builds.

When applying an update, Vaultcast:

1. downloads only the changed files from GitHub and checks each one against its
   git blob SHA, refusing the update if any file does not match;
2. shows visitors a short maintenance page while files are swapped;
3. replaces only the application's own files, after backing them up to
   `data/updates/`. Your settings, database, media, caches, uploads, downloads
   and (on Windows) the bundled runtimes under `data/runtime/` are never touched;
4. restores the backup automatically if anything goes wrong;
5. applies any database changes the next time a page loads.

If the server was interrupted mid-update, the next update attempt finishes the
rollback first. The last three backups are kept. If the check reports that the
repository is private or that GitHub's hourly limit has been used up, ask the
publisher for an update token and add it to `data/config.php` as
`'update_token' => '...'`. A fresh Windows or Linux install can update straight
to the latest version: the updater compares the installed application folder
with GitHub and only downloads what has changed.
