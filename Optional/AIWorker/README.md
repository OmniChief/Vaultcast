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
