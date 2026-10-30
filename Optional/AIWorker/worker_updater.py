"""Pinned GitHub updates, verified Git blobs, rollback and restart (Windows)."""
import ctypes
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.parse
import urllib.request
import uuid

REPO = "OmniChief/Vaultcast"
PREFIX = "Optional/AIWorker/"
CHANNELS = {"Stable": "main", "Preview": "feat/ai-images-worker"}
STATE = Path(os.environ.get("APPDATA", str(Path.home()))) / "VaultcastAIWorker" / "updates"
FLAGS = getattr(subprocess, "CREATE_NO_WINDOW", 0)


def read_json(path, default=None):
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else default
    except (OSError, ValueError):
        return default


def write_json(path, data):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name(path.name + ".tmp")
    temp.write_text(json.dumps(data), encoding="utf-8")
    temp.replace(path)


def fetch(url, limit=2097152):
    req = urllib.request.Request(url, headers={"User-Agent": "Vaultcast-Worker-Updater", "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(req, timeout=45) as response:
        data = response.read(limit + 1)
    if len(data) > limit:
        raise ValueError("Update response exceeds its allowed size.")
    return data


def allowed(name):
    return bool(re.fullmatch(r"[A-Za-z0-9_-]+\.(?:py|ps1|cmd|md)", name) or name == "VERSION")


def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()


def version(text):
    if not re.fullmatch(r"\d+\.\d+\.\d+", text.strip()):
        raise ValueError("The update has no valid worker version.")
    return tuple(map(int, text.strip().split(".")))


def check(root, channel):
    root = Path(root).resolve()
    branch = CHANNELS[channel]
    api = "https://api.github.com/repos/" + REPO
    commit = json.loads(fetch(api + "/commits/" + urllib.parse.quote(branch, safe="")))
    sha = commit["sha"]
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("GitHub did not return a valid update commit.")
    tree = json.loads(fetch(api + "/git/trees/" + commit["commit"]["tree"]["sha"] + "?recursive=1", 8388608))
    if tree.get("truncated"):
        raise ValueError("The update file list is incomplete. Try again later.")
    files = {}
    for item in tree["tree"]:
        if not item["path"].startswith(PREFIX):
            continue
        name = item["path"][len(PREFIX):]
        if not allowed(name):
            continue
        if item.get("type") != "blob" or item.get("mode") not in ("100644", "100755") or not re.fullmatch(r"[0-9a-f]{40}", item["sha"]):
            raise ValueError("The update contains an unsupported file entry.")
        files[name] = item["sha"]
    if not all(name in files for name in ("VERSION", "worker_manager.py", "worker_updater.py", "launch-manager.ps1")):
        raise ValueError("This channel does not publish an update-capable worker yet. Try Preview or check again after release.")
    raw = "https://raw.githubusercontent.com/" + REPO + "/" + sha + "/" + PREFIX
    data = fetch(raw + "VERSION", 128)
    if blob(data) != files["VERSION"]:
        raise ValueError("Worker version integrity check failed.")
    remote = data.decode("utf-8").strip()
    local = root.joinpath("VERSION").read_text(encoding="utf-8").strip()
    changed = []
    for name, expected in files.items():
        path = root / name
        if path.is_symlink():
            raise ValueError("Worker files must not be symbolic links: " + name)
        current = path.read_bytes() if path.is_file() else None
        if current is None or (blob(current) != expected and blob(current.replace(b"\r\n", b"\n")) != expected):
            changed.append(name)
    newer = version(remote) > version(local)
    return {"root": str(root), "channel": channel, "commit": sha, "files": files, "raw": raw,
            "version": remote, "current": local, "changed": changed,
            "available": version(remote) >= version(local) and bool(changed), "newer": newer}


def stage(plan, progress, cancel):
    directory = STATE / ("staged-" + uuid.uuid4().hex)
    directory.mkdir(parents=True)
    files = plan["files"]
    if len(files) > 128:
        raise ValueError("The worker update contains too many files.")
    total = 0
    for index, (name, expected) in enumerate(files.items()):
        if cancel.is_set():
            raise InterruptedError("Update canceled. Installed files have not changed.")
        data = fetch(plan["raw"] + urllib.parse.quote(name))
        total += len(data)
        if total > 16777216 or blob(data) != expected:
            raise ValueError("Update integrity check failed: " + name)
        if name.endswith(".py"):
            ast.parse(data, filename=name)
        directory.joinpath(name).write_bytes(data)
        progress("Downloading update: %d/%d files" % (index+1, len(files)))
    manifest = dict(plan, directory=str(directory), parent=os.getpid(), python=sys.executable)
    # Apply with the currently installed helper, not code from a future release.
    shutil.copyfile(__file__, directory / "apply-helper.py")
    write_json(directory / "manifest.json", manifest)
    return directory / "manifest.json"


def launch(manifest):
    manifest = Path(manifest)
    subprocess.Popen([sys.executable, str(manifest.parent / "apply-helper.py"), "--apply", str(manifest)],
                     cwd=manifest.parent, creationflags=FLAGS)


def replace(source, target):
    for attempt in range(30):
        temp = target.with_name(target.name + ".vc-new")
        try:
            shutil.copyfile(source, temp)
            os.replace(temp, target)
            return
        except OSError:
            if attempt == 29:
                raise
            time.sleep(0.2)


def apply_files(manifest):
    plan = read_json(manifest)
    root, directory = Path(plan["root"]).resolve(), Path(plan["directory"]).resolve()
    if directory != Path(manifest).resolve().parent or not directory.is_relative_to(STATE.resolve()):
        raise ValueError("Invalid update staging folder.")
    # Take a handle while the old manager still exists; never replace loaded files.
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.OpenProcess.argtypes = [ctypes.c_ulong, ctypes.c_int, ctypes.c_ulong]
    kernel.OpenProcess.restype = ctypes.c_void_p
    kernel.WaitForSingleObject.argtypes = [ctypes.c_void_p, ctypes.c_ulong]
    kernel.CloseHandle.argtypes = [ctypes.c_void_p]
    handle = kernel.OpenProcess(0x00100000, False, int(plan["parent"]))
    if handle:
        try:
            if kernel.WaitForSingleObject(handle, 120000) != 0:
                raise RuntimeError("The worker manager did not close; update was not applied.")
        finally:
            kernel.CloseHandle(handle)
    elif ctypes.get_last_error() != 87:
        raise RuntimeError("Could not confirm the previous manager has exited.")
    backup = STATE / ("backup-" + uuid.uuid4().hex)
    backup.mkdir()
    replaced = []
    try:
        for name, expected in plan["files"].items():
            if not allowed(name) or (root / name).is_symlink() or blob((directory / name).read_bytes()) != expected:
                raise ValueError("Update file failed validation: " + name)
        # Save all originals before making any changes. Unknown local files are retained.
        for name in plan["files"]:
            if (root / name).exists():
                shutil.copy2(root / name, backup / name)
        for name in plan["files"]:
            replaced.append(name)
            replace(directory / name, root / name)
        write_json(STATE / "result.json", {"ok": True, "version": plan["version"], "backup": str(backup)})
    except Exception as error:
        failures = []
        for name in reversed(replaced):
            try:
                if (backup / name).exists():
                    replace(backup / name, root / name)
                else:
                    (root / name).unlink(missing_ok=True)
            except OSError:
                failures.append(name)
        write_json(STATE / "result.json", {"ok": False, "error": str(error), "restore_failed": failures, "backup": str(backup)})
        if failures:
            return
    # Use the normal bootstrap so new Python dependencies are handled by the app.
    subprocess.Popen([str(Path(os.environ["SystemRoot"]) / "System32/WindowsPowerShell/v1.0/powershell.exe"),
                      "-NoProfile", "-WindowStyle", "Hidden", "-File", str(root / "launch-manager.ps1")],
                     cwd=root, creationflags=FLAGS)


def apply(manifest):
    import msvcrt
    with (STATE / "install.lock").open("a+b") as lock:
        if lock.tell() == 0:
            lock.write(b"0")
            lock.flush()
        lock.seek(0)
        try:
            msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
        except OSError:
            raise RuntimeError("Another worker update is already installing.")
        try:
            apply_files(manifest)
        finally:
            lock.seek(0)
            msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)


if __name__ == "__main__" and len(sys.argv) == 3 and sys.argv[1] == "--apply":
    try:
        apply(sys.argv[2])
    except Exception as error:
        write_json(STATE / "result.json", {"ok": False, "error": str(error)})
