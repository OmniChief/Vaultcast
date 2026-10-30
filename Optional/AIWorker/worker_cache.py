"""Worker-owned scratch files only. Models, environments and settings are excluded."""
from contextlib import contextmanager
from functools import wraps
import json
import os
from pathlib import Path
import re
import shutil
import time

ROOT = Path(os.environ.get("USERPROFILE", str(Path.home()))) / ".vaultcast"
LEGACY = Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "VaultcastAIWorker"
UPDATES = Path(os.environ.get("APPDATA", str(Path.home()))) / "VaultcastAIWorker/updates"


def linked(path):
    return path.is_symlink() or bool(getattr(path.stat(follow_symlinks=False), "st_file_attributes", 0) & 0x400)


def walk(path):
    if not path.exists() or linked(path):
        return
    if path.is_file():
        yield path
    else:
        for child in path.iterdir():
            if not linked(child):
                yield from walk(child)


def remove(path, parent):
    path, parent = Path(path), Path(parent)
    if linked(path) or not path.resolve().is_relative_to(parent.resolve()) or path.resolve() == parent.resolve():
        raise ValueError("Refusing to clear an unexpected cache path.")
    # Reject junctions anywhere inside a candidate tree before recursive removal.
    def validate(folder):
        if linked(folder):
            raise ValueError("Cache contains a link; it was left intact.")
        if folder.is_dir():
            for child in folder.iterdir():
                validate(child)
    validate(path)
    size = sum(p.stat().st_size for p in walk(path))
    if path.is_dir():
        shutil.rmtree(path)
    else:
        path.unlink()
    return size


class CacheBusy(RuntimeError):
    pass


@contextmanager
def guard(exclusive=False, lock_name="cache.lock"):
    if lock_name not in ("cache.lock", "gpu-gate.lock"):
        raise ValueError("Invalid worker resource lock.")
    ROOT.mkdir(parents=True, exist_ok=True)
    with (ROOT / lock_name).open("a+b") as file:
        if os.name == "nt":
            import ctypes
            from ctypes import wintypes
            import msvcrt
            class Overlapped(ctypes.Structure):
                _fields_ = [("internal", ctypes.c_size_t), ("high", ctypes.c_size_t), ("offset", wintypes.DWORD), ("offset_high", wintypes.DWORD), ("event", wintypes.HANDLE)]
            kernel = ctypes.WinDLL("kernel32", use_last_error=True)
            kernel.LockFileEx.argtypes = [wintypes.HANDLE, wintypes.DWORD, wintypes.DWORD, wintypes.DWORD, wintypes.DWORD, ctypes.POINTER(Overlapped)]
            kernel.UnlockFileEx.argtypes = [wintypes.HANDLE, wintypes.DWORD, wintypes.DWORD, wintypes.DWORD, ctypes.POINTER(Overlapped)]
            handle, overlapped = msvcrt.get_osfhandle(file.fileno()), Overlapped()
            if not kernel.LockFileEx(handle, 1 | (2 if exclusive else 0), 0, 1, 0, ctypes.byref(overlapped)):
                raise CacheBusy("Worker resource is in use by another operation. Try again when workers are stopped.")
            try:
                yield
            finally:
                kernel.UnlockFileEx(handle, 0, 1, 0, ctypes.byref(overlapped))
        else:
            import fcntl
            try:
                fcntl.flock(file, (fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH) | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise CacheBusy("Worker resource is in use by another operation.") from error
            try:
                yield
            finally:
                fcntl.flock(file, fcntl.LOCK_UN)


def cache_use(fn):
    @wraps(fn)
    def wrapped(*args, **kwargs):
        with guard():
            return fn(*args, **kwargs)
    return wrapped


def entries():
    seen = set()
    for base in (ROOT, LEGACY):
        if base.exists() and linked(base):
            continue
        conversions = base / "conversions"
        if conversions.is_dir() and not linked(conversions):
            for owner in conversions.iterdir():
                if not re.fullmatch(r"[a-f0-9]{24}", owner.name) or linked(owner) or not owner.is_dir():
                    continue
                for job in owner.iterdir():
                    if not re.fullmatch(r"[a-f0-9]{16}", job.name) or linked(job) or not job.is_dir():
                        continue
                    try:
                        confirmed = json.loads((job / "state.json").read_text(encoding="utf-8")).get("upload_confirmed", False) is True
                    except (OSError, ValueError, AttributeError):
                        confirmed = False
                    key = str(job.resolve())
                    if key not in seen:
                        seen.add(key)
                        yield job, owner, "reclaimable" if confirmed else "resumable"
        for name in ("downloads", "tmp", "generated-videos", "generations"):
            folder = base / name
            if folder.is_dir() and not linked(folder):
                for child in folder.iterdir():
                    key = str(child.resolve())
                    if key not in seen:
                        seen.add(key)
                        yield child, folder, "resumable" if name in ("generated-videos", "generations") or child.name.endswith((".download", ".part")) else "reclaimable"
    config = Path(os.environ.get("APPDATA", str(Path.home()))) / "VaultcastAIWorker/worker.json"
    try:
        settings = json.loads(config.read_text(encoding="utf-8"))
        engine = Path(settings.get("comfy_root") or ROOT / "ComfyUI")
        folder = engine / "output" / "Vaultcast"
        if folder.is_dir() and not any(linked(p) for p in (engine, engine / "output", folder)):
            for child in folder.iterdir():
                yield child, folder, "resumable"
    except (OSError, ValueError, AttributeError):
        pass
    if UPDATES.is_dir() and not linked(UPDATES):
        backups = sorted(UPDATES.glob("backup-*"), key=lambda p:p.stat().st_mtime, reverse=True)
        for path in list(UPDATES.glob("staged-*")) + backups:
            # Keep the latest rollback backup and all recent update transactions.
            protected = path in backups[:1] or time.time()-path.stat().st_mtime <= 7*86400
            yield path, UPDATES, "protected" if protected else "reclaimable"


def inventory():
    totals = {"reclaimable": 0, "resumable": 0, "protected": 0, "errors": 0}
    for path, _, kind in entries():
        try:
            totals[kind] += sum(file.stat().st_size for file in walk(path))
        except OSError:
            totals["errors"] += 1
    return totals


def clear(discard=False):
    removed, errors = 0, []
    with guard(exclusive=True):
        for path, parent, kind in list(entries()):
            if kind == "protected":
                continue
            if kind == "resumable" and not discard:
                continue
            try:
                removed += remove(path, parent)
            except (OSError, ValueError) as error:
                errors.append(str(error))
    return removed, errors


def prune_updates():
    for path, parent, kind in entries():
        if parent == UPDATES and kind == "reclaimable":
            remove(path, parent)


def maintenance():
    """Retry confirmed cleanup only; never expire unacknowledged job data."""
    try:
        with guard(exclusive=True):
            # Retry acknowledged AI-file cleanup using only the journal's own outputs.
            journals = ROOT / "generations"
            if journals.is_dir() and not linked(journals):
                for journal in journals.glob("*.json"):
                    try:
                        record = json.loads(journal.read_text(encoding="utf-8"))
                        if record.get("delivered") is not True:
                            continue
                        output_root = Path(record["engine_root"]) / "output" / "Vaultcast"
                        for node in record.get("outputs", {}).values():
                            for kind in ("images", "videos", "gifs"):
                                for item in node.get(kind, []):
                                    path = Path(record["engine_root"]) / "output" / item.get("subfolder", "") / item.get("filename", "")
                                    if item.get("filename") and item.get("type", "output") == "output" and path.is_file() and path.resolve().is_relative_to(output_root.resolve()):
                                        remove(path, output_root)
                        journal.unlink()
                    except (OSError, ValueError, KeyError, AttributeError):
                        pass
            for path, parent, kind in list(entries()):
                if kind == "reclaimable" and (re.fullmatch(r"[a-f0-9]{16}", path.name) or parent == UPDATES):
                    try:
                        remove(path, parent)
                    except (OSError, ValueError):
                        pass
    except (OSError, RuntimeError):
        pass


def size_text(value):
    amount = float(value)
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if amount < 1000 or unit == "TB":
            return "%.1f %s" % (amount, unit)
        amount /= 1000
