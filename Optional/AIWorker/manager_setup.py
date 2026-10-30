"""Noninteractive setup operations invoked by explicit manager actions."""
import copy
from pathlib import Path
import shutil
import subprocess
import time

from comfy_backend import Engine, ROOT, FLAGS, download, stopped
from contributor_setup import hardware
from encoding_worker import BUILD, GpuCoordinator, check_encoder
import worker
from worker_cache import cache_use


def save(config):
    Path(worker.CONFIG_DIR).mkdir(parents=True, exist_ok=True)
    worker.save_config(config)


@cache_use
def prepare(config, slots, log, progress, cancel):
    cfg = copy.deepcopy(config)
    cfg["image_backend"] = "comfyui"
    cfg["hardware"] = hardware()
    cfg["slots"] = slots
    cfg["contributions"] = {k: any(s.get(k) for s in slots) for k in ("images", "encoding", "video")}
    caps = cfg.setdefault("capabilities", {})
    if cfg["contributions"]["images"]:
        engine = Engine(cfg)
        engine.status()
        models = engine.models()
        if not models:
            raise ValueError("Install an SDXL image model before enabling an image worker.")
        cfg["models"], cfg["model_name"] = models, models[0]["name"]
        # This checks installation readiness, without running generation during setup.
        caps["images"] = {"sdxl": {"verified": True, "max_pixels": 1048576, "checked_at": int(time.time()), "model_hash": models[0]["hash"]}}
    else:
        caps["images"] = {}
        cfg.setdefault("model_name", "")
    caps["video"] = {"verified": False}
    if cfg["contributions"]["video"]:
        engine = Engine(cfg)
        engine.status()
        if not engine.video_ready():
            raise ValueError("Download the Wan video model pack before enabling an AI video worker.")
        nodes = engine.call("/object_info")
        if not all(name in nodes for name in ("Wan22ImageToVideoLatent", "CreateVideo", "SaveVideo")):
            raise ValueError("Update ComfyUI to a version with native Wan 2.2 and video support.")
        caps["video"] = {"verified": True}
    if cfg["contributions"]["encoding"]:
        import urllib.request
        import zipfile
        ffmpeg = cfg.get("ffmpeg") or shutil.which("ffmpeg") or str(ROOT / "ffmpeg/ffmpeg.exe")
        ffprobe = cfg.get("ffprobe") or str(Path(ffmpeg).with_name("ffprobe.exe"))
        if not Path(ffmpeg).is_file() or not Path(ffprobe).is_file():
            log("Downloading portable FFmpeg (about 110 MB).")
            with urllib.request.urlopen(BUILD + ".sha256", timeout=30) as response:
                expected = response.read(2048).decode().split()[0].lower()
            if len(expected) != 64 or any(c not in "0123456789abcdef" for c in expected):
                raise ValueError("FFmpeg checksum is unavailable.")
            archive = download(BUILD, ROOT / "downloads/ffmpeg.zip", expected, progress, cancel)
            with zipfile.ZipFile(archive) as package:
                for name in ("ffmpeg.exe", "ffprobe.exe"):
                    item = next(p for p in package.namelist() if p.endswith("/bin/" + name))
                    target = ROOT / "ffmpeg" / name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with package.open(item) as source, target.open("wb") as output:
                        shutil.copyfileobj(source, output)
            archive.unlink(missing_ok=True)
            ffmpeg, ffprobe = str(ROOT / "ffmpeg/ffmpeg.exe"), str(ROOT / "ffmpeg/ffprobe.exe")
        used = set()
        for slot in slots:
            stopped(cancel)
            if not slot.get("encoding"):
                continue
            cores = slot.get("cpu_cores", [])
            if not cores or used.intersection(cores) or not set(cores).issubset(worker.available_cpu_cores()):
                raise ValueError("Each encoding worker needs available CPU cores that do not overlap other workers.")
            used.update(cores)
            encoder = slot.get("encoding_encoder", "libx264")
            if encoder not in ("libx264", "h264_nvenc", "h264_qsv", "h264_amf"):
                raise ValueError("Choose a supported hardware encoder.")
            log("Checking " + encoder + " availability using a short FFmpeg sample.")
            temp = ROOT / "tmp"
            temp.mkdir(parents=True, exist_ok=True)
            local = dict(cfg, _stop_event=cancel)
            with GpuCoordinator().reserve(local):
                if encoder != "libx264" and (cfg["contributions"]["images"] or cfg["contributions"]["video"]):
                    engine = Engine(cfg)
                    if not engine.idle():
                        raise ValueError("Wait for the AI engine to finish its current work before checking hardware encoding.")
                    engine.free()
                check_encoder(ffmpeg, ffprobe, encoder, slot.get("encoding_device", 0), len(cores), temp)
        cfg.update(ffmpeg=ffmpeg, ffprobe=ffprobe)
        caps["encoding"] = {"verified": True, "profile": "mp4-h264-aac"}
    else:
        caps["encoding"] = {"verified": False}
    save(cfg)
    return cfg
