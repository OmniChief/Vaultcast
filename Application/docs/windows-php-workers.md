# Windows: PHP workers and media streaming (for Theodore)

> **Status: implemented in the installer** (branch `installer/win-media-accel`).
>
> - **Media location:** `vaultcast.conf` now has `location ~ "^/_vaultcast_media/([A-Za-z]):/(.*)$" { internal; alias $1:/$2; sendfile on; }`. Under the bundled Windows nginx, `alias /;` resolves to the nginx drive (`Z:/C:/...`) and fails.
> - **PHP pool:** a php-cgi pool of N services (default 4, `/PHPWORKERS=`) sits behind `upstream vaultcast_php`, which is option B below. php-cgi on Windows doesn't fork children, so `PHP_FCGI_CHILDREN` (option A) doesn't help there.
> - **Turning it on:** `setup.json` gains `"mediaAccel": true`, so `nginx_accel` turns on. UNC paths fall back to PHP sendFile.
> - **Existing installs:** run the installer's Update or repair.

## Root cause the admin hit

The Windows Full installer runs **one** `php-cgi.exe` bound to a port
(`php-cgi.exe -c "...\php.ini" -b 127.0.0.1:$PhpPort` via WinSW). There is no
`PHP_FCGI_CHILDREN` and nginx’s generated `vaultcast.conf` has **no**
`/_vaultcast_media/` location.

When a browser plays a video, PHP serves the file itself (`Stream::sendFile`,
HTTP 206 ranges) for the whole duration of the stream. That single worker is
busy for minutes. Every other API call — `/api/progress`, `/api/sections/…/items`,
`/admin/convert/pending`, poster fetches — queues behind it. DevTools shows those
calls taking 30s–2+ minutes with **no PHP error** in `php-errors.log`.

Vaultcast already prefers **X-Accel-Redirect** when
`settings.streaming.nginx_accel` is true (Linux installer defaults it on;
Windows defaults it **off** because the location was omitted).

There is no per-user PHP session-file lock on the API (sessions are DB cookies).
`session_write_close()` is still called after auth so any native session cannot
add a second lock. Progress posts are a cheap upsert and the player does not
await them. Those help with multi-worker setups; they **cannot** unblock a
single worker that is stuck inside `sendFile`.

## Required installer change (preferred)

In `Installers/Windows/NSIS/include/Configure.nsh`, when writing
`vaultcast.conf`, add the same internal location Linux ships in
`Application/deploy/nginx.conf.example`:

```nginx
# PHP only issues this internal redirect after resolving an allowed library file.
# nginx serves byte ranges without tying up php-cgi.
location ^~ /_vaultcast_media/ {
    internal;
    # Absolute paths (including Windows drive letters as C:/...) after the prefix.
    alias /;
    sendfile on;
}
```

On Windows nginx, if `alias /;` does not resolve `C:/…` correctly, use a regex
map instead, for example:

```nginx
location ~ ^/_vaultcast_media/(?<drive>[A-Za-z])/(?<rest>.*)$ {
    internal;
    alias $drive:/$rest;
    sendfile on;
}
```

(Vaultcast emits `/_vaultcast_media/` + rawurlencoded absolute path with forward
slashes, e.g. `/_vaultcast_media/C%3A/Media/film.mp4`.)

Then either:

1. Set `'streaming' => ['nginx_accel' => true, …]` for Windows installs in
   `Installer.php` (today it is `PHP_OS_FAMILY !== 'Windows'`), **or**
2. Flip `nginx_accel` on in the generated `data/config` / settings after the
   location exists.

With that in place, `Stream::play` / `playRom` exit immediately after the
`X-Accel-Redirect` header and **nginx** serves the media. The php-cgi worker
is free for library clicks while a movie plays.

## Interim / belt-and-braces: multiple php-cgi workers

Even with X-Accel, more than one worker helps for concurrent API work (probe,
posters, admin). Options:

### A. `PHP_FCGI_CHILDREN` (simplest)

WinSW service environment for the PHP service:

```xml
<env name="PHP_FCGI_CHILDREN" value="4" />
<env name="PHP_FCGI_MAX_REQUESTS" value="500" />
```

Keep the existing `-b 127.0.0.1:$PhpPort` argument. Classic php-cgi spawns that
many children on the one port.

### B. nginx upstream pool of several php-cgi processes

Run e.g. four WinSW services on `9000`–`9003` and:

```nginx
upstream vaultcast_php {
    server 127.0.0.1:9000;
    server 127.0.0.1:9001;
    server 127.0.0.1:9002;
    server 127.0.0.1:9003;
}
# fastcgi_pass vaultcast_php;
```

Prefer **A + X-Accel** over B unless children prove unreliable on a given PHP
build.

## What not to do

- Do not make library folders public under nginx `alias` without `internal` —
  that bypasses playback tokens.
- Do not rely on raising `fastcgi_read_timeout` alone; that only lengthens the
  queue wait.

## App-side already done (PR #20)

- `release_request_locks()` / `session_write_close` after auth and before stream.
- Progress: cheap upsert, throttled revision bump, `sendBeacon` / keepalive from
  the player (no await).
- `emitAccelOrSend()` when `nginx_accel` is on.
- Remote poster/synopsis wall-clock budget so one Wikipedia call cannot pin the
  worker for a minute.

After the installer ships `/_vaultcast_media/` and turns `nginx_accel` on for
Windows, open a movie and confirm DevTools: `play?t=…` should be a short PHP
response (or nginx 206) while `/api/sections/…/items` stays in the tens of
milliseconds.
