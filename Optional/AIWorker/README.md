# Vaultcast Worker Manager

Windows only. Extract this entire folder and double-click **start-worker.cmd**.
The lightweight Python/Tkinter manager replaces the default console menu.
The bootstrap prepares a private Python environment and opens the manager.
If Python is missing, install 64-bit Python 3.12 or 3.13 from python.org with
Tcl/Tk enabled. Existing InvokeAI installations and models are preserved.

## Setup

1. For AI generation, open **Setup & models**, choose the engine folder and GPU
   installation profile, then install and start the managed ComfyUI engine.
   CPU conversion does not require an AI engine.
2. Download SDXL for images, or the Wan 2.2 video pack for videos. Model downloads
   are several gigabytes; the buttons show estimates. Review the publishers'
   model licenses first. Existing SDXL checkpoints can also be imported without
   moving or deleting their originals.
3. Choose **Configure workers**. Each worker has one role: AI images, AI video
   generation, CPU conversion, or NVIDIA/Intel/AMD hardware conversion.
   Give each conversion worker non-overlapping CPU cores. GPU numbering starts
   at zero. **Check & save** checks installed models and runs a short FFmpeg
   sample for conversion profiles. It does not generate AI media.
4. Open **Connection & settings**. Enter the Vaultcast address and sign in with
   your Vaultcast username/password, or use a one-time pairing code. Credentials
   must belong to an active account; conversion also needs Library access.
   Passwords and temporary browser sessions are not saved. The worker credential
   is stored in your existing AppData worker configuration.
5. Start workers individually or together. Workers receive tasks from Vaultcast.

The manager has no prompt editor or local generation button. Submit images and
videos inside Vaultcast. The AI Studio image page has an **AI video generation**
button for short text-to-video clips. Video generation initially supports about
2, 3.3 or 5 seconds at 512×320 or 768×448, with a 64 MiB upload limit. It does not
charge or reward contribution points in this first implementation. These video
jobs are separate from converting existing library videos.

## Managing workers

**Finish & stop** stops taking jobs and completes the current one. **Stop now**
interrupts work and preserves conversion checkpoints. AI generation cannot resume
halfway through diffusion: it may start again after interruption. Completed AI
outputs are retained locally so upload retries do not require generation again.
AI video uploads use 2 MiB chunks and resume at the server-confirmed byte offset.

Stop workers before applying configuration changes; this prevents an active
upload changing servers or CPU allocations halfway through a job. Reconfigure
and restart from the same manager. Image/video generation and hardware encoding
take turns using the shared GPU lock. CPU conversion can continue alongside them.
GPU protection covers workers running under the same Windows account; unrelated
software and sessions do not participate in that lock. The managed engine listens
on loopback and is intended exclusively for these workers.

The worker dashboard scrolls for larger installations. Progress is indeterminate
when the engine cannot report a true percentage. Conversion status includes
transfer rate and estimated time remaining where available. Details are recorded
under `%USERPROFILE%\.vaultcast`; use **Open logs** to find them. Conversion files
and pairing are retained across manager restarts. Keep logs private: job names
or prompts may be present.

**Both the Vaultcast app and workers need this update** for AI video jobs and
the new manager. The old console is available with `start-worker.cmd --console`.
Uninstall using `uninstall-worker.cmd`; managed-engine removal remains an
explicit separate choice. No models are bundled in Git.

Validation for this change is syntax-only. Windows GUI behavior, GPU capability,
model compatibility and generation quality require checking on the target PC.

## In-app updates (1.5.6 and later)

Open **Updates** to check for and install worker updates. Startup checks can be
disabled there; installing is always your choice. Preview follows
`feat/ai-images-worker`, while Stable follows `main`. Stable (main) is the default for new installations and invalid saved channels.
An explicitly selected channel is preserved. Preview is optional. Updates only
include versions published on the selected channel, not unmerged pull requests.

The updater pins one GitHub commit, verifies downloaded Git blob hashes, stages
all files, then finishes running jobs before closing and replacing worker files.
It backs up originals and attempts rollback on installation errors. It restarts
through the normal launcher so missing Python dependencies can be installed.
Workers remain stopped after restart. Pairing, configuration, models and retained
conversions are kept. **Update logs & backups** opens the update working folder.
Network failures, GitHub rate limits and channels without a worker release are
shown in the update status. The updater cannot automatically install itself into
older workers; install an update-capable worker once to enable future in-app updates.

If a selected update branch is deleted, checking for updates offers to switch
to Stable (main). Accepting saves the channel and immediately checks main.
Declining keeps the existing choice. Network errors and rate limits do not
trigger a channel switch.

## Cache and disk space

Connection & settings shows total worker cache, reclaimable bytes, retained job
bytes and protected update backups. Refresh size rescans; it also refreshes every
30 seconds. Models, installed Python environments and engines are excluded.
Clear cache requires stopped workers. Normal clearing preserves resumable work;
selecting the discard option also removes unfinished conversions/downloads and
AI output caches, so those jobs may need to restart. Close older worker copies
before using that option. New workers share a process-safe cache lock.

Validated conversions release the downloaded source and checkpoint copies before
uploading; the final MP4 remains for upload recovery. Confirmed uploads remove
the whole job workspace. Delivered AI images/videos release their generated files.
Old update staging/backups are pruned after seven days while retaining the latest
rollback backup and all recent transactions. Unconfirmed work is not expired
silently: inspect retained bytes and explicitly discard it if no longer needed.
Shared system pip/uv caches, external InvokeAI files and installed models are not
cleared. New managed AI engine installs disable uv package caching to avoid an
extra copy of large GPU packages.

## Closing the manager

Confirming the window's Close prompt now exits immediately without waiting for
server requests. It terminates the manager's owned Windows process group,
including native encoding/probe processes, managed ComfyUI and setup children.
Saved checkpoints and downloads remain, but an unfinished checkpoint section or
AI generation can need restarting. Server-side leases expire normally; shutdown
does not wait for an unavailable server. Existing external AI engines and
unrelated applications are not terminated. The updater helper is explicitly
separate so an in-app update can still apply files and restart the manager.

## NVIDIA driver compatibility

NVIDIA setup first checks the configured/current FFmpeg. If that build requires
an NVENC API newer than the installed driver, drivers supporting NVENC 12.2 can
use a private FFmpeg 7.1 compatibility runtime downloaded and SHA-256 verified
by setup. The encoder is checked again before saving the worker. This older
runtime is selected only for the NVIDIA slot; other workers and system FFmpeg
are not replaced. A current driver remains preferable for using current FFmpeg.
Unsupported drivers/devices show a short error with details in Activity. No
silent switch to CPU encoding is made. Runtime files are installed automatically
by setup; updater delivery includes the compatibility-selection code.
