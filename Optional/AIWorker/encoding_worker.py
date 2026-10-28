"""Optional full-file MP4/H.264/AAC contribution; independent of InvokeAI."""
import hashlib
from contextlib import nullcontext
import http.client
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import urllib.parse
import zipfile
from contributor_setup import DATA, yes

FLAGS = getattr(subprocess, "CREATE_NO_WINDOW", 0)
BUILD = "https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip"


class ConsoleProgress:
    """Refresh the local display independently of slow server progress requests."""
    def __init__(self, snapshot, stream=None):
        self.snapshot = snapshot
        self.stream = stream or sys.stdout
        self.terminal = self.stream.isatty()
        self.stop = threading.Event()
        self.lock = threading.Lock()
        self.width = 0
        self.last_emit = 0
        self.sample = None
        self.closed = False
        self.thread = threading.Thread(target=self._run, daemon=True)

    @staticmethod
    def size(value):
        for unit in ("B", "KiB", "MiB", "GiB", "TiB"):
            if value < 1024 or unit == "TiB": return f"{value:.1f} {unit}"
            value /= 1024

    @staticmethod
    def remaining(seconds):
        seconds = max(0, int(seconds))
        return f"{seconds // 3600:d}:{seconds // 60 % 60:02d}:{seconds % 60:02d}" if seconds >= 3600 else f"{seconds // 60:02d}:{seconds % 60:02d}"

    def start(self):
        self.thread.start()

    def render(self, force=False):
        data = self.snapshot()
        phase = data.get("phase", "download")
        percent = max(0, min(100, float(data.get("phase_progress", 0))))
        now = time.monotonic()
        done, total = int(data.get("bytes_done", 0)), int(data.get("bytes_total", 0))
        with self.lock:
            changed = self.sample is None or self.sample["phase"] != phase
            if changed: self.sample = {"phase": phase, "at": now, "done": done, "rate": 0, "started": now}
            sample = self.sample
            elapsed = now - sample["at"]
            if elapsed >= 0.5:
                rate = max(0, done - sample["done"]) / elapsed
                sample["rate"] = rate if not sample["rate"] else sample["rate"] * 0.5 + rate * 0.5
                sample.update(at=now, done=done)
            if not self.terminal and not force and not changed and now - self.last_emit < 5: return
            labels = {"download": "Download", "encode": "Encoding", "upload": "Upload", "verify": "Finalising", "done": "Completed"}
            filled = int(percent / 100 * 20)
            bar = "#" * filled + "-" * (20 - filled)
            text = f"{labels.get(phase, phase):10} [{bar}] {percent:5.1f}%"
            if phase in ("download", "upload"):
                text += f"  {self.size(done)} / {self.size(total)}"
                if sample["rate"] > 0:
                    text += f"  {self.size(sample['rate'])}/s  ETA {self.remaining((total-done)/sample['rate'])}"
            elif phase == "encode" and 0 < percent < 100:
                text += "  ETA " + self.remaining((now - sample["started"]) * (100 - percent) / percent)
            elif phase == "verify":
                text = "Finalising: replacing original " + "|/-\\"[int(now * 2) % 4]
            if self.terminal:
                columns = max(40, shutil.get_terminal_size((120, 25)).columns)
                text = text[:columns - 1]
                self.stream.write("\r" + text + " " * max(0, self.width - len(text)))
                self.stream.flush()
                self.width = len(text)
            else:
                print(text, file=self.stream, flush=True)
            self.last_emit = now

    def log(self, *args, **kwargs):
        with self.lock:
            if self.width:
                self.stream.write("\r" + " " * self.width + "\r")
                self.width = 0
            print(*args, file=self.stream, **kwargs)
            self.stream.flush()

    def close(self):
        self.stop.set()
        if self.thread.is_alive(): self.thread.join(timeout=2)
        with self.lock:
            if not self.closed and self.width:
                self.stream.write("\n")
                self.stream.flush()
            self.width = 0
            self.closed = True

    def _run(self):
        while not self.stop.is_set():
            self.render()
            self.stop.wait(0.5)


class ChunkUploader:
    """Keep one connection open across bounded, individually acknowledged chunks."""
    def __init__(self, url, headers):
        self.url = url
        self.target = urllib.parse.urlsplit(url)
        self.headers = headers
        self.connection = None

    def close(self):
        if self.connection:
            self.connection.close()
        self.connection = None

    def send(self, chunk, offset, on_sent):
        if self.connection is None:
            constructor = http.client.HTTPSConnection if self.target.scheme == "https" else http.client.HTTPConnection
            self.connection = constructor(self.target.hostname, self.target.port, timeout=120)
        connection = self.connection
        try:
            path = self.target.path + ("?" + self.target.query if self.target.query else "")
            connection.putrequest("POST", path)
            for key, value in {**self.headers, "Content-Type": "application/octet-stream", "Accept": "application/json",
                "Content-Length": str(len(chunk)), "X-Upload-Offset": str(offset)}.items():
                connection.putheader(key, value)
            connection.endheaders()
            send_error = None
            try:
                for at in range(0, len(chunk), 65536):
                    piece = chunk[at:at + 65536]
                    connection.send(piece)
                    on_sent(offset + at + len(piece))
            except OSError as exc:
                # A proxy can reject an oversized body before it has all been sent.
                send_error = exc
            response = connection.getresponse()
            content = response.read()
            if response.status >= 400:
                raise urllib.error.HTTPError(self.url, response.status, response.reason, response.headers, io.BytesIO(content))
            if send_error:
                raise send_error
            result = json.loads(content)
            if result.get("received") != offset + len(chunk):
                raise RuntimeError("Server did not acknowledge the complete chunk.")
            return result
        except BaseException:
            self.close()
            raise


def digest(path):
    value = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1048576), b""):
            value.update(chunk)
    return value.hexdigest()


def probe(binary, path):
    return json.loads(subprocess.check_output([binary, "-v", "error", "-show_streams", "-show_format", "-of", "json", str(path)],
        timeout=45, creationflags=FLAGS))


def transfer_root(cfg):
    owner = hashlib.sha256((cfg["server"] + "|" + cfg["worker_id"]).encode()).hexdigest()[:24]
    return DATA / "conversions" / owner


def save_transfer(root, state):
    temporary = root / "state.tmp"
    temporary.write_text(json.dumps(state), encoding="utf-8")
    temporary.replace(root / "state.json")


def pending_encodings(cfg):
    jobs = []
    for file in sorted(transfer_root(cfg).glob("*/state.json")):
        try:
            state = json.loads(file.read_text(encoding="utf-8"))
            if not state.get("waiting_for_claim"):
                jobs.append(state["job"])
        except (OSError, ValueError, KeyError):
            print("Could not read retained conversion state:", file)
    return jobs


def retryable_upload(error):
    if isinstance(error, urllib.error.HTTPError):
        return error.code >= 500 or error.code in (408, 429)
    return isinstance(error, (urllib.error.URLError, OSError, http.client.HTTPException))


def cleanup_transfer(root, cfg):
    # Delete only this confirmed job's private workspace, never the parent contribution folder.
    resolved = root.resolve()
    if resolved.parent != transfer_root(cfg).resolve() or not re.fullmatch(r"[a-f0-9]{16}", resolved.name):
        raise RuntimeError("Refusing to clean an unexpected conversion folder.")
    try:
        shutil.rmtree(resolved)
        print("Confirmed upload; local downloaded source and converted file deleted.")
    except OSError as error:
        print("Upload confirmed, but local cleanup needs retry:", root, error)


def prepare_encoding(config, force=False):
    directory = DATA / "ffmpeg"
    ffmpeg = config.get("ffmpeg") or shutil.which("ffmpeg") or str(directory / "ffmpeg.exe")
    ffprobe = config.get("ffprobe") or shutil.which("ffprobe") or str(Path(ffmpeg).with_name("ffprobe.exe" if os.name == "nt" else "ffprobe"))
    if not Path(ffmpeg).is_file() or not Path(ffprobe).is_file():
        if os.name != "nt":
            raise RuntimeError("Install FFmpeg and ffprobe with libx264/AAC support using your package manager, then rerun setup.")
        if not yes("Download portable FFmpeg/ffprobe from gyan.dev (about 110 MB), only for this contributor?"):
            raise RuntimeError("Encoding needs FFmpeg and ffprobe.")
        directory.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="vaultcast-ffmpeg-") as temporary:
            archive = Path(temporary) / "ffmpeg.zip"
            with urllib.request.urlopen(BUILD + ".sha256", timeout=30) as response:
                expected = response.read(2048).decode().strip().split()[0].lower()
            if not re.fullmatch(r"[a-f0-9]{64}", expected):
                raise RuntimeError("FFmpeg download checksum was unavailable.")
            print("Downloading FFmpegâ€¦", flush=True)
            with urllib.request.urlopen(BUILD, timeout=120) as response, archive.open("wb") as output:
                shutil.copyfileobj(response, output, 1048576)
            if digest(archive) != expected:
                raise RuntimeError("FFmpeg checksum did not match. Rerun setup to retry.")
            with zipfile.ZipFile(archive) as package:
                for name in ("ffmpeg.exe", "ffprobe.exe"):
                    member = next((m for m in package.namelist() if m.endswith("/bin/" + name)), None)
                    if not member:
                        raise RuntimeError("FFmpeg package is missing " + name)
                    with package.open(member) as source, (directory / name).open("wb") as output:
                        shutil.copyfileobj(source, output)
        ffmpeg, ffprobe = str(directory / "ffmpeg.exe"), str(directory / "ffprobe.exe")
    version = subprocess.check_output([ffmpeg, "-version"], text=True, timeout=15, creationflags=FLAGS).splitlines()[0]
    profile = (config.get("capabilities") or {}).get("encoding") or {}
    if force or not profile.get("verified") or config.get("ffmpeg_version") != version:
        default = max(1, min(8, (os.cpu_count() or 2) - 1))
        answer = input("CPU threads for encoding [" + str(default) + "]: ").strip()
        threads = int(answer) if answer else default
        if not 1 <= threads <= (os.cpu_count() or 1):
            raise RuntimeError("Choose a CPU thread count between 1 and " + str(os.cpu_count() or 1))
        print("Checking H.264/AAC encoding with a one-second local clipâ€¦")
        with tempfile.TemporaryDirectory(prefix="vaultcast-encoder-check-") as temporary:
            output = Path(temporary) / "check.mp4"
            subprocess.run([ffmpeg, "-nostdin", "-v", "error", "-f", "lavfi", "-i", "color=size=128x128:rate=10",
                "-f", "lavfi", "-i", "anullsrc=r=48000:cl=stereo", "-t", "1", "-c:v", "libx264", "-threads", str(threads),
                "-pix_fmt", "yuv420p", "-c:a", "aac", "-movflags", "+faststart", str(output)], check=True, timeout=60, creationflags=FLAGS)
            streams = probe(ffprobe, output)["streams"]
            if not any(s.get("codec_name") == "h264" for s in streams) or not any(s.get("codec_name") == "aac" for s in streams):
                raise RuntimeError("FFmpeg did not pass the H.264/AAC check.")
        config["encoding_threads"] = threads
        print("Encoding profile passed. No GPU is required for this CPU encoder.")
    return {"ffmpeg": ffmpeg, "ffprobe": ffprobe, "ffmpeg_version": version,
        "encoding_threads": config.get("encoding_threads", 1), "encoding_profile": {"verified": True, "profile": "mp4-h264-aac"}}


def run_encoding(cfg, job, request):
    base = cfg["server"] + "/api/ai/worker/encoding/jobs/" + job["id"]
    headers = {"X-Vaultcast-Worker": cfg["worker_token"], "X-Vaultcast-Lease": job["lease"]}
    if not re.fullmatch(r"[a-f0-9]{16}", str(job["id"])):
        raise RuntimeError("Invalid conversion job ID.")
    workspace = transfer_root(cfg) / job["id"]
    workspace.mkdir(parents=True, exist_ok=True)
    state_file = workspace / "state.json"
    state = json.loads(state_file.read_text(encoding="utf-8")) if state_file.exists() else {}
    previous = state.get("job", {})
    if previous and (previous.get("bytes") != job["bytes"] or previous.get("source_mtime") != job.get("source_mtime")):
        raise RuntimeError("Source changed; retained conversion files kept at " + str(workspace))
    state.update(job=job, waiting_for_claim=False)
    save_transfer(workspace, state)
    if state.get("upload_confirmed"):
        cleanup_transfer(workspace, cfg)
        return
    try:
        remote = request(base + "/status", "POST", {}, headers, timeout=20)
    except urllib.error.HTTPError as error:
        if error.code < 500 and error.code not in (408, 429):
            state["waiting_for_claim"] = True
            save_transfer(workspace, state)
        raise
    if remote.get("uploaded"):
        state["upload_confirmed"] = True
        save_transfer(workspace, state)
        cleanup_transfer(workspace, cfg)
        return
    if remote.get("completed"):
        state["waiting_for_claim"] = True
        save_transfer(workspace, state)
        raise RuntimeError("Server no longer has the confirmed output. Local files kept at " + str(workspace))
    stop = threading.Event()
    lost = threading.Event()
    status = {"progress": 0, "stage": "downloading source", "phase": "download", "phase_progress": 0,
        "bytes_done": 0, "bytes_total": job["bytes"], "rate_bps": 0, "eta_seconds": 0}
    last_success = [time.monotonic()]
    sample = {"phase": "download", "at": time.monotonic(), "bytes": 0, "rate": 0}
    update_lock = threading.Lock()
    display = ConsoleProgress(lambda: dict(status))

    def phase(name, label, progress, total=0):
        status.update(phase=name, stage=label, progress=progress, phase_progress=0,
            bytes_done=0, bytes_total=total, rate_bps=0, eta_seconds=0)

    def transferred(done, total, start, weight):
        status.update(bytes_done=done, bytes_total=total, phase_progress=round(done / max(1, total) * 100, 1),
            progress=round(start + done / max(1, total) * weight, 1))

    def update():
        if not update_lock.acquire(blocking=False): return
        try:
            snapshot = dict(status)
            now = time.monotonic()
            if sample["phase"] != snapshot["phase"]:
                sample.update(phase=snapshot["phase"], at=now, bytes=snapshot["bytes_done"], rate=0)
            elapsed = now - sample["at"]
            if elapsed >= 1:
                rate = max(0, snapshot["bytes_done"] - sample["bytes"]) / elapsed
                rate = rate if not sample["rate"] else sample["rate"] * 0.5 + rate * 0.5
                sample.update(at=now, bytes=snapshot["bytes_done"], rate=rate)
            rate = sample["rate"]
            snapshot["rate_bps"] = round(rate)
            snapshot["eta_seconds"] = round(max(0, snapshot["bytes_total"] - snapshot["bytes_done"]) / rate) if rate > 0 else 0
            request(base + "/progress", "POST", snapshot, headers, timeout=15)
            last_success[0] = time.monotonic()
        except urllib.error.HTTPError as exc:
            if exc.code in (401, 403, 409):
                lost.set()
        except Exception:
            pass
        finally:
            update_lock.release()
        if time.monotonic() - last_success[0] > 180:
            lost.set()

    def heartbeat():
        while not stop.wait(2):
            update()

    print("Converting:", job["title"], flush=True)
    display.start()
    update()
    thread = threading.Thread(target=heartbeat, daemon=True)
    thread.start()
    process = None
    try:
        with nullcontext(str(workspace)) as temporary:
            root = Path(temporary)
            source, output = root / "source.video", root / "directplay.mp4"
            if not state.get("output_ready") or not output.is_file() or output.stat().st_size != state.get("output_size"):
                required = int(job["bytes"]) * 3 + 1073741824
                if shutil.disk_usage(root).free < required:
                    raise RuntimeError("Not enough local temporary disk space (need about " + str(round(required / 1073741824, 1)) + " GB).")
                if not source.is_file() or source.stat().st_size != job["bytes"]:
                    received = 0
                    with urllib.request.urlopen(urllib.request.Request(base + "/source", headers=headers), timeout=120) as response, source.open("wb") as file:
                        while True:
                            if lost.is_set(): raise RuntimeError("Conversion lease was canceled or lost.")
                            chunk = response.read1(262144)
                            if not chunk: break
                            file.write(chunk)
                            received += len(chunk)
                            transferred(received, job["bytes"], 0, 10)
                    if received != job["bytes"]:
                        raise RuntimeError("Source download was incomplete.")
                info = probe(cfg["ffprobe"], source)
                video = next((s for s in info["streams"] if s.get("codec_type") == "video" and not (s.get("disposition") or {}).get("attached_pic")), None)
                if not video: raise RuntimeError("Source contains no video stream.")
                audio = [s for s in info["streams"] if s.get("codec_type") == "audio"]
                duration = float((info.get("format") or {}).get("duration") or job.get("duration") or 0)
                copy_video = job.get("smart") and video.get("codec_name") == "h264" and video.get("pix_fmt") == "yuv420p"
                copy_audio = job.get("smart") and all(s.get("codec_name") == "aac" for s in audio)
                threads = str(cfg.get("encoding_threads", 1))
                command = [cfg["ffmpeg"], "-nostdin", "-y", "-hide_banner", "-loglevel", "error", "-threads", threads,
                    "-filter_threads", threads, "-progress", str(root / "progress"), "-nostats", "-i", str(source), "-map", "0:" + str(video["index"]), "-map", "0:a?",
                    "-map_metadata", "0", "-map_chapters", "0", "-c:v", "copy" if copy_video else "libx264"]
                if not copy_video:
                    command += ["-threads", threads, "-preset", job["preset"], "-crf", str(job["crf"]), "-pix_fmt", "yuv420p",
                        "-vf", "pad=ceil(iw/2)*2:ceil(ih/2)*2"]
                command += ["-c:a", "copy" if copy_audio else "aac"]
                if not copy_audio: command += ["-b:a", job["audio_bitrate"]]
                command += ["-movflags", "+faststart", "-f", "mp4", str(output)]
                phase("encode", "encoding", 10)
                update()
                with (root / "ffmpeg.log").open("wb") as log:
                    process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=log, creationflags=FLAGS)
                    try:
                        while process.poll() is None:
                            if lost.wait(1): raise RuntimeError("Conversion canceled or contributor lease lost.")
                            try:
                                with (root / "progress").open("rb") as progress_file:
                                    progress_file.seek(max(0, progress_file.seek(0, 2) - 8192))
                                    content = progress_file.read().decode(errors="replace")
                                values = re.findall(r"out_time_us=(\d+)", content)
                                if values and duration > 0:
                                    status["progress"] = round(10 + min(80, int(values[-1]) / 1000000 / duration * 80), 1)
                                    status["phase_progress"] = round(min(100, int(values[-1]) / 1000000 / duration * 100), 1)
                            except OSError:
                                pass
                        if process.returncode:
                            raise RuntimeError((root / "ffmpeg.log").read_text(errors="replace")[-600:] or "FFmpeg failed.")
                    finally:
                        if process.poll() is None:
                            process.terminate()
                            try: process.wait(timeout=10)
                            except subprocess.TimeoutExpired:
                                process.kill(); process.wait()
                check = probe(cfg["ffprobe"], output)
                if not any(s.get("codec_name") == "h264" for s in check["streams"]):
                    raise RuntimeError("Converted file failed validation.")
                state.update(output_ready=True, output_size=output.stat().st_size)
                save_transfer(workspace, state)
            else:
                display.log("Reusing retained conversion; resuming upload.")
            size = output.stat().st_size
            if lost.is_set(): raise RuntimeError("Conversion canceled or lease lost.")
            phase("upload", "uploading converted file", 90, size)
            update()
            remote = request(base + "/status", "POST", {}, headers, timeout=20)
            sent = int(remote.get("received", 0))
            if sent < 0 or sent > size: raise RuntimeError("Server upload offset exceeds the retained output.")
            transferred(sent, size, 90, 9)
            chunk_size = max(262144, min(4194304, int(job.get("upload_chunk_bytes", 1048576))))
            uploader = ChunkUploader(base + "/chunk", headers)
            try:
                with output.open("rb") as file:
                    while sent < size:
                        if lost.is_set(): raise RuntimeError("Conversion canceled or lease lost.")
                        file.seek(sent)
                        chunk = file.read(chunk_size)
                        smaller = False
                        for attempt in range(3):
                            try:
                                uploader.send(chunk, sent, lambda done: transferred(done, size, 90, 9))
                                break
                            except urllib.error.HTTPError as exc:
                                if exc.code == 413 and chunk_size > 262144:
                                    chunk_size //= 2
                                    display.log("Server upload limit: reducing chunks to", chunk_size // 1024, "KiB")
                                    smaller = True
                                    break
                                if exc.code < 500 or attempt == 2: raise
                                time.sleep(2)
                            except (OSError, ValueError, http.client.HTTPException):
                                # Some proxies reset the socket before their 413 response can be read.
                                if chunk_size > 262144 and attempt == 0:
                                    chunk_size = max(262144, min(1048576, chunk_size // 2))
                                    display.log("Upload connection interrupted; retrying with", chunk_size // 1024, "KiB chunks")
                                    smaller = True
                                    break
                                if attempt == 2: raise
                                time.sleep(2)
                        if smaller:
                            remote = request(base + "/status", "POST", {}, headers, timeout=20)
                            sent = int(remote.get("received", 0))
                            if not 0 <= sent <= size: raise RuntimeError("Invalid server upload offset.")
                            transferred(sent, size, 90, 9)
                            continue
                        sent += len(chunk)
                        transferred(sent, size, 90, 9)
            finally:
                uploader.close()
            phase("verify", "replacing original", 99)
            update()
            # Completion owns the queue lock while replacing the original; stop progress first.
            stop.set()
            thread.join(timeout=25)
            for attempt in range(3):
                try:
                    confirmation = request(base + "/complete", "POST", {"bytes": size}, headers, timeout=1800)
                    if not confirmation.get("uploaded"):
                        raise RuntimeError("Server did not confirm receipt; update Vaultcast before retrying this worker.")
                    break
                except urllib.error.HTTPError as exc:
                    if exc.code < 500 or attempt == 2: raise
                    time.sleep(3)
                except OSError:
                    if attempt == 2: raise
                    time.sleep(3)
            status.update(phase="done", phase_progress=100, progress=100)
            display.render(force=True)
            display.close()
            print("Conversion uploaded and original replaced." if confirmation.get("replaced") else "Conversion uploaded; replacement needs administrator attention.")
            if confirmation.get("warning"): print("Server note:", confirmation["warning"])
            state["upload_confirmed"] = True
            save_transfer(workspace, state)
            cleanup_transfer(workspace, cfg)
    except BaseException as exc:
        if process is not None and process.poll() is None:
            process.terminate()
            try: process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill(); process.wait()
        transient = retryable_upload(exc) or isinstance(exc, KeyboardInterrupt)
        state["waiting_for_claim"] = lost.is_set() or not transient
        save_transfer(workspace, state)
        display.close()
        print("Unconfirmed files retained for retry:", workspace)
        if not transient and not lost.is_set():
            try: request(base + "/fail", "POST", {"error": str(exc)}, headers, timeout=15)
            except Exception: pass
        raise
    finally:
        stop.set()
        thread.join(timeout=25)
        display.close()
