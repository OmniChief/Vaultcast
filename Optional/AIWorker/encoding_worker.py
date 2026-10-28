"""Optional full-file MP4/H.264/AAC contribution; independent of InvokeAI."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
import zipfile
from contributor_setup import DATA, yes

FLAGS = getattr(subprocess, "CREATE_NO_WINDOW", 0)
BUILD = "https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip"


def digest(path):
    value = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1048576), b""):
            value.update(chunk)
    return value.hexdigest()


def probe(binary, path):
    return json.loads(subprocess.check_output([binary, "-v", "error", "-show_streams", "-show_format", "-of", "json", str(path)],
        timeout=45, creationflags=FLAGS))


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
    stop = threading.Event()
    lost = threading.Event()
    status = {"progress": 0, "stage": "downloading source"}
    last_success = [time.monotonic()]

    def update():
        try:
            request(base + "/progress", "POST", dict(status), headers, timeout=20)
            last_success[0] = time.monotonic()
        except urllib.error.HTTPError as exc:
            if exc.code in (401, 403, 409):
                lost.set()
        except Exception:
            pass
        if time.monotonic() - last_success[0] > 180:
            lost.set()

    def heartbeat():
        while not stop.wait(20):
            update()

    update()
    thread = threading.Thread(target=heartbeat, daemon=True)
    thread.start()
    process = None
    try:
        print("Converting:", job["title"], flush=True)
        with tempfile.TemporaryDirectory(prefix="vaultcast-convert-") as temporary:
            root = Path(temporary)
            source, output = root / "source.video", root / "directplay.mp4"
            required = int(job["bytes"]) * 3 + 1073741824
            if shutil.disk_usage(root).free < required:
                raise RuntimeError("Not enough local temporary disk space (need about " + str(round(required / 1073741824, 1)) + " GB).")
            received = 0
            with urllib.request.urlopen(urllib.request.Request(base + "/source", headers=headers), timeout=120) as response, source.open("wb") as file:
                while True:
                    if lost.is_set(): raise RuntimeError("Conversion lease was canceled or lost.")
                    chunk = response.read(1048576)
                    if not chunk: break
                    file.write(chunk)
                    received += len(chunk)
                    status["progress"] = round(min(10, received / max(1, job["bytes"]) * 10), 1)
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
            status.update(stage="encoding", progress=10)
            update()
            with (root / "ffmpeg.log").open("wb") as log:
                process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=log, creationflags=FLAGS)
                try:
                    while process.poll() is None:
                        if lost.wait(1): raise RuntimeError("Conversion canceled or contributor lease lost.")
                        try:
                            content = (root / "progress").read_text(errors="replace")[-8192:]
                            values = re.findall(r"out_time_us=(\d+)", content)
                            if values and duration > 0:
                                status["progress"] = round(10 + min(80, int(values[-1]) / 1000000 / duration * 80), 1)
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
            size = output.stat().st_size
            sha = digest(output)
            if lost.is_set(): raise RuntimeError("Conversion canceled or lease lost.")
            status.update(stage="uploading converted file", progress=90)
            update()
            sent = 0
            with output.open("rb") as file:
                for chunk in iter(lambda: file.read(1048576), b""):
                    if lost.is_set(): raise RuntimeError("Conversion canceled or lease lost.")
                    for attempt in range(3):
                        try:
                            req = urllib.request.Request(base + "/chunk", data=chunk, method="POST", headers={**headers,
                                "Content-Type": "application/octet-stream", "X-Upload-Offset": str(sent)})
                            with urllib.request.urlopen(req, timeout=120) as response:
                                result = json.loads(response.read())
                            if result.get("received") != sent + len(chunk): raise RuntimeError("Server did not acknowledge the complete chunk.")
                            break
                        except urllib.error.HTTPError as exc:
                            if exc.code < 500 or attempt == 2: raise
                            time.sleep(2)
                        except (OSError, ValueError):
                            if attempt == 2: raise
                            time.sleep(2)
                    sent += len(chunk)
                    status["progress"] = round(90 + sent / max(1, size) * 9, 1)
            status.update(stage="server verifying output", progress=99)
            # Completion owns the queue lock while hashing/probing; stop progress calls first.
            stop.set()
            thread.join(timeout=25)
            for attempt in range(3):
                try:
                    request(base + "/complete", "POST", {"bytes": size, "sha256": sha}, headers, timeout=1800)
                    break
                except urllib.error.HTTPError as exc:
                    if exc.code < 500 or attempt == 2: raise
                    time.sleep(3)
                except OSError:
                    if attempt == 2: raise
                    time.sleep(3)
            print("Conversion uploaded and added to Vaultcast.")
    except BaseException as exc:
        if process is not None and process.poll() is None:
            process.terminate()
            try: process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill(); process.wait()
        try:
            request(base + "/fail", "POST", {"error": "Contributor stopped" if isinstance(exc, KeyboardInterrupt) else str(exc)}, headers, timeout=15)
        except Exception:
            pass
        raise
    finally:
        stop.set()
        thread.join(timeout=25)
