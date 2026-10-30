"""Local ComfyUI engine, verified downloads and native image/video workflows."""
import hashlib
import json
import logging
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile
from worker_cache import cache_use

ROOT = Path(os.environ.get("USERPROFILE", str(Path.home()))) / ".vaultcast"
ENGINE_VERSION = "v0.43.0"
DEFAULT_URL = "http://127.0.0.1:8188"
FLAGS = getattr(subprocess, "CREATE_NO_WINDOW", 0)
PACKS = {
    "images": [("stabilityai/stable-diffusion-xl-base-1.0", "sd_xl_base_1.0.safetensors", "checkpoints")],
    "video": [
        ("Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "split_files/diffusion_models/wan2.2_ti2v_5B_fp16.safetensors", "diffusion_models"),
        ("Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "split_files/vae/wan2.2_vae.safetensors", "vae"),
        ("Comfy-Org/Wan_2.1_ComfyUI_repackaged", "split_files/text_encoders/umt5_xxl_fp8_e4m3fn_scaled.safetensors", "text_encoders"),
    ],
}


def atomic_json(file, value):
    file = Path(file)
    file.parent.mkdir(parents=True, exist_ok=True)
    temporary = file.with_name(file.name + "." + uuid.uuid4().hex + ".tmp")
    temporary.write_text(json.dumps(value, indent=2), encoding="utf-8")
    temporary.replace(file)


def api(url, method="GET", data=None, timeout=30):
    body = json.dumps(data).encode() if data is not None else None
    headers = {"Accept": "application/json", "User-Agent": "Vaultcast-Worker-Manager"}
    if body is not None:
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, body, headers, method=method)
    with urllib.request.urlopen(request, timeout=timeout) as response:
        raw = response.read()
        return json.loads(raw) if raw else {}


def stopped(cancel):
    if cancel is not None and cancel.is_set():
        raise InterruptedError("Stopped. Completed downloads and conversions have been kept.")


def run(command, log, cancel=None, cwd=None):
    """Stream installer messages without blocking the GUI or opening a console."""
    process = subprocess.Popen(command, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        text=True, encoding="utf-8", errors="replace", creationflags=FLAGS)
    def cancel_process():
        while process.poll() is None:
            if cancel is not None and cancel.wait(0.25):
                process.terminate()
                return
            if cancel is None:
                time.sleep(0.25)
    threading.Thread(target=cancel_process, daemon=True).start()
    try:
        for line in process.stdout:
            log(line.rstrip())
        code = process.wait()
        stopped(cancel)
        if code:
            raise RuntimeError("Installation stopped (code " + str(code) + "). See Activity for the details.")
    finally:
        if process.poll() is None:
            process.terminate()


def download(url, target, expected=None, progress=lambda *args: None, cancel=None):
    target = Path(target)
    target.parent.mkdir(parents=True, exist_ok=True)
    partial = target.with_suffix(target.suffix + ".download")
    for attempt in range(4):
        stopped(cancel)
        offset = partial.stat().st_size if partial.exists() else 0
        headers = {"User-Agent": "Vaultcast-Worker-Manager", "Accept-Encoding": "identity"}
        if offset:
            headers["Range"] = "bytes=" + str(offset) + "-"
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=60) as response:
                if response.status == 206:
                    if not response.headers.get("Content-Range", "").startswith("bytes " + str(offset) + "-"):
                        raise RuntimeError("Download server returned the wrong resume position.")
                else:
                    offset = 0
                total = offset + int(response.headers.get("Content-Length", 0))
                with partial.open("ab" if offset else "wb") as output:
                    done = offset
                    while True:
                        stopped(cancel)
                        block = response.read1(1048576)
                        if not block:
                            break
                        output.write(block)
                        done += len(block)
                        progress("Downloading " + target.name, done, total)
                if total and done != total:
                    raise OSError("Download ended early; it will resume automatically.")
            if expected:
                digest = hashlib.sha256()
                with partial.open("rb") as source:
                    done = 0
                    for block in iter(lambda: source.read(4194304), b""):
                        stopped(cancel)
                        digest.update(block)
                        done += len(block)
                        progress("Checking " + target.name, done, partial.stat().st_size)
                if digest.hexdigest() != expected:
                    partial.unlink()
                    raise RuntimeError("Downloaded model failed its integrity check. Please download it again.")
            partial.replace(target)
            return target
        except (urllib.error.URLError, OSError) as error:
            stopped(cancel)
            if isinstance(error, urllib.error.HTTPError) and error.code == 416:
                # A completed partial file may have been interrupted before rename.
                if expected and partial.exists():
                    digest = hashlib.sha256()
                    with partial.open("rb") as source:
                        for block in iter(lambda: source.read(4194304), b""):
                            stopped(cancel)
                            digest.update(block)
                    if digest.hexdigest() == expected:
                        partial.replace(target)
                        return target
                partial.unlink(missing_ok=True)
                continue
            if isinstance(error, urllib.error.HTTPError) and error.code not in (408, 429, 500, 502, 503, 504):
                raise
            if attempt == 3:
                raise
            if cancel is not None:
                cancel.wait(2 + attempt * 2)
            else:
                time.sleep(2 + attempt * 2)


class Engine:
    def __init__(self, config):
        self.config = config
        self.root = Path(config.get("comfy_root") or ROOT / "ComfyUI").expanduser().resolve()
        self.url = config.get("comfy_url", DEFAULT_URL).rstrip("/")
        self.process = None

    def call(self, path, method="GET", data=None, timeout=30):
        return api(self.url + path, method, data, timeout)

    def status(self):
        return self.call("/system_stats", timeout=5)

    def idle(self):
        queue = self.call("/queue", timeout=10)
        return not queue.get("queue_running") and not queue.get("queue_pending")

    def free(self):
        if self.idle():
            self.call("/free", "POST", {"unload_models": True, "free_memory": True})

    @cache_use
    def install(self, log, progress, cancel, accelerator="cu128"):
        marker = self.root / ".vaultcast-managed"
        if self.root.exists() and any(self.root.iterdir()) and not marker.exists():
            raise RuntimeError("Choose an empty folder for a managed engine. Your existing installation has not been changed.")
        self.root.mkdir(parents=True, exist_ok=True)
        if shutil.disk_usage(self.root).free < 25 * 1073741824:
            raise RuntimeError("Choose a drive with at least 25 GB free for the engine. Models need more space.")
        marker.touch()
        archive = ROOT / "downloads" / ("ComfyUI-" + ENGINE_VERSION + ".zip")
        download("https://codeload.github.com/Comfy-Org/ComfyUI/zip/refs/tags/" + ENGINE_VERSION,
            archive, progress=progress, cancel=cancel)
        with zipfile.ZipFile(archive) as source:
            prefix = source.namelist()[0].split("/")[0] + "/"
            for member in source.infolist():
                stopped(cancel)
                relative = member.filename[len(prefix):]
                target = (self.root / relative).resolve()
                if not target.is_relative_to(self.root) or (member.external_attr >> 16) & 0o170000 == 0o120000:
                    raise RuntimeError("Engine archive contains an unsafe path.")
                if not relative or member.is_dir():
                    continue
                target.parent.mkdir(parents=True, exist_ok=True)
                with source.open(member) as incoming, target.open("wb") as output:
                    shutil.copyfileobj(incoming, output)
        archive.unlink(missing_ok=True)
        python = self.root / ".venv" / "Scripts" / "python.exe"
        uv = [sys.executable, "-m", "uv", "--no-cache"]
        if not python.exists():
            run(uv + ["venv", "--python", "3.12", "--managed-python", str(python.parent.parent)], log, cancel)
        if accelerator not in ("cu128", "cu126", "cpu"):
            raise ValueError("Choose a supported GPU installation profile.")
        packages = ["torch", "torchvision"] if accelerator != "cu126" else ["torch==2.7.1", "torchvision==0.22.1"]
        run(uv + ["pip", "install", "--python", str(python)] + packages +
            ["--index-url", "https://download.pytorch.org/whl/" + accelerator], log, cancel)
        run(uv + ["pip", "install", "--python", str(python), "-r", str(self.root / "requirements.txt")], log, cancel)
        atomic_json(self.root / "vaultcast-install.json", {"version": ENGINE_VERSION, "accelerator": accelerator})
        log("AI engine installed. Model packs can now be downloaded from Engines & models.")

    def start(self, log, cancel=None):
        try:
            self.status()
            log("Connected to the running AI engine.")
            return
        except Exception:
            pass
        parsed = urllib.parse.urlsplit(self.url)
        if parsed.hostname not in ("localhost", "127.0.0.1", "::1"):
            raise RuntimeError("Start the remote AI engine on its own computer, then connect again.")
        python = self.root / ".venv/Scripts/python.exe"
        if not python.is_file() or not (self.root / "main.py").is_file():
            raise RuntimeError("Install the AI engine first, or choose an existing ComfyUI folder in Settings.")
        self.root.joinpath("user").mkdir(exist_ok=True)
        command = [str(python), str(self.root / "main.py"), "--listen", "127.0.0.1", "--port", str(parsed.port or 8188)]
        command += ["--disable-auto-launch", "--fp32-vae"]
        ROOT.mkdir(parents=True, exist_ok=True)
        try:
            installed = json.loads((self.root / "vaultcast-install.json").read_text(encoding="utf-8"))
        except (OSError, ValueError):
            installed = {}
        cpu_only = installed.get("accelerator") == "cpu"
        if cpu_only:
            command.append("--cpu")
        if not cpu_only and self.config.get("low_vram", True):
            command.append("--lowvram")
        if not cpu_only and self.config.get("ai_gpu_device") not in (None, ""):
            command += ["--cuda-device", str(int(self.config["ai_gpu_device"]))]
        with (ROOT / "comfyui.log").open("ab") as output:
            self.process = subprocess.Popen(command, cwd=self.root, stdout=output, stderr=output, creationflags=FLAGS)
        for _ in range(180):
            stopped(cancel)
            if self.process.poll() is not None:
                raise RuntimeError("The AI engine stopped. Open its log from Activity for the reason.")
            try:
                self.status()
                log("AI engine is ready.")
                return
            except (OSError, ValueError):
                if cancel is not None:
                    cancel.wait(2)
                else:
                    time.sleep(2)
        raise RuntimeError("The AI engine is still starting. Check its log and try Connect again.")

    def stop(self):
        if self.process and self.process.poll() is None:
            self.process.terminate()
            self.process.wait(timeout=30)
        self.process = None

    def install_pack(self, kind, progress, cancel):
        for repository, filename, folder in PACKS[kind]:
            metadata = api("https://huggingface.co/api/models/" + repository + "?blobs=true", timeout=60)
            record = next(item for item in metadata["siblings"] if item["rfilename"] == filename)
            expected = record.get("lfs", {}).get("sha256") or record.get("lfs", {}).get("oid")
            if not expected or len(expected) != 64:
                raise RuntimeError("The model provider did not return a SHA-256 checksum; the download was not started.")
            target = self.root / "models" / folder / Path(filename).name
            if target.is_file():
                digest = hashlib.sha256()
                with target.open("rb") as source:
                    for block in iter(lambda: source.read(4194304), b""):
                        stopped(cancel)
                        digest.update(block)
                if digest.hexdigest() == expected:
                    progress("Already installed: " + target.name, 1, 1)
                    continue
            url = "https://huggingface.co/" + repository + "/resolve/" + metadata["sha"] + "/" + filename
            download(url, target, expected, progress, cancel)

    def models(self):
        info = self.call("/object_info/CheckpointLoaderSimple")
        names = info["CheckpointLoaderSimple"]["input"]["required"]["ckpt_name"][0]
        cache_file = ROOT / "model-hashes.json"
        try:
            cache = json.loads(cache_file.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            cache = {}
        models = []
        for name in names:
            path = (self.root / "models/checkpoints" / name).resolve()
            if not path.is_relative_to((self.root / "models/checkpoints").resolve()) or not path.is_file() or path.suffix != ".safetensors":
                continue
            stat = path.stat()
            with path.open("rb") as source:
                length = int.from_bytes(source.read(8), "little")
                if not 0 < length < 16777216:
                    continue
                header = json.loads(source.read(length))
            if not any("conditioner.embedders.1." in key or "text_encoder_2" in key for key in header):
                continue
            stamp = [stat.st_size, stat.st_mtime_ns]
            record = cache.get(str(path), {})
            if record.get("stamp") != stamp:
                digest = hashlib.sha256()
                with path.open("rb") as source:
                    for block in iter(lambda: source.read(4194304), b""):
                        digest.update(block)
                record = {"stamp": stamp, "sha256": digest.hexdigest()}
                cache[str(path)] = record
            models.append({"key": name, "name": Path(name).stem, "hash": record["sha256"], "base": "sdxl", "type": "main"})
        atomic_json(cache_file, cache)
        return models

    def video_ready(self):
        return all((self.root / "models" / folder / Path(file).name).is_file() for _, file, folder in PACKS["video"])

    def generate(self, graph, task, cancel, progress):
        if not isinstance(task, str) or not task.strip():
            raise ValueError("A claimed Vaultcast job identity is required for generation.")
        identity = hashlib.sha256(json.dumps(graph, sort_keys=True).encode()).hexdigest()
        journal = ROOT / "generations" / (hashlib.sha256(task.encode()).hexdigest() + ".json")
        prompt_id = str(uuid.uuid5(uuid.NAMESPACE_URL, task + identity))
        try:
            record = json.loads(journal.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            record = {}
        if record.get("identity") == identity and record.get("outputs"):
            return record["outputs"]
        if record.get("identity") == identity and record.get("prompt_id"):
            prompt_id = record["prompt_id"]
        queue = self.call("/queue")
        history = self.call("/history/" + prompt_id).get(prompt_id)
        known = any(item[1] == prompt_id for item in queue.get("queue_running", []) + queue.get("queue_pending", []))
        if history and not known and history.get("status", {}).get("status_str") == "error":
            messages = history.get("status", {}).get("messages", [])
            if any(isinstance(message, list) and message and message[0] == "execution_interrupted" for message in messages):
                prompt_id = str(uuid.uuid4())
                history = None
        if not known and not history:
            atomic_json(journal, {"identity": identity, "prompt_id": prompt_id})
            self.call("/prompt", "POST", {"prompt": graph, "client_id": prompt_id, "prompt_id": prompt_id})
        atomic_json(journal, {"identity": identity, "prompt_id": prompt_id})
        try:
            while True:
                stopped(cancel)
                history = self.call("/history/" + prompt_id).get(prompt_id)
                if history:
                    if history.get("status", {}).get("status_str") == "error":
                        messages = history.get("status", {}).get("messages", [])
                        raise RuntimeError("AI generation failed: " + str(messages)[-1500:])
                    outputs = history.get("outputs") or {}
                    if history.get("status", {}).get("completed") and outputs:
                        atomic_json(journal, {"identity": identity, "prompt_id": prompt_id, "outputs": outputs})
                        progress(100, "Generation complete")
                        return outputs
                progress(None, "AI engine is generating; this can take several minutes")
                if cancel is not None:
                    cancel.wait(2)
                else:
                    time.sleep(2)
        except BaseException:
            try:
                self.call("/queue", "POST", {"delete": [prompt_id]})
                self.call("/interrupt", "POST", {"prompt_id": prompt_id})
                for _ in range(60):
                    running = self.call("/queue").get("queue_running", [])
                    if not any(item[1] == prompt_id for item in running):
                        break
                    time.sleep(0.5)
            except Exception:
                logging.exception("Could not confirm interrupted AI job has stopped")
            raise

    def release_output(self, task):
        """Delete only this worker's known generated files after server acknowledgement."""
        journal = ROOT / "generations" / (hashlib.sha256(task.encode()).hexdigest() + ".json")
        try:
            record = json.loads(journal.read_text(encoding="utf-8"))
            from worker_cache import remove
            record["delivered"] = True
            record["engine_root"] = str(self.root)
            atomic_json(journal, record)
            allowed = self.root / "output" / "Vaultcast"
            for node in record.get("outputs", {}).values():
                for key in ("images", "videos", "gifs"):
                    for item in node.get(key, []):
                        if item.get("type", "output") != "output":
                            continue
                        path = self.root / "output" / item.get("subfolder", "") / item.get("filename", "")
                        if item.get("filename") and path.is_file() and path.resolve().is_relative_to(allowed.resolve()):
                            remove(path, allowed)
            journal.unlink(missing_ok=True)
        except (OSError, ValueError, KeyError):
            logging.exception("Delivered AI output cache cleanup failed")

    def output(self, outputs, video=False):
        for node in outputs.values():
            for key in (("videos", "gifs", "images") if video else ("images",)):
                for item in node.get(key, []):
                    extension = Path(item.get("filename", "")).suffix.lower()
                    if video and extension not in (".mp4", ".webm"):
                        continue
                    url = self.url + "/view?" + urllib.parse.urlencode({k: item.get(k, "") for k in ("filename", "subfolder", "type")})
                    return url, item["filename"]
        raise RuntimeError("The AI engine returned no usable " + ("video" if video else "image") + " output.")


def image_graph(job, model):
    if not job.get("id"):
        raise ValueError("Images must be generated from a claimed Vaultcast job.")
    seed = job.get("seed") if job.get("seed") is not None else int(hashlib.sha256(str(job["id"]).encode()).hexdigest()[:12], 16)
    if job.get("hidiffusion") or job.get("seamless_x") or job.get("seamless_y"):
        raise ValueError("This ComfyUI worker does not support HiDiffusion or seamless generation. Disable those options in Vaultcast.")
    scheduler = job.get("scheduler", "euler_a")
    karras = scheduler.endswith("_k")
    name = scheduler[:-2] if karras else scheduler
    name = {"euler_a": "euler_ancestral", "unipc": "uni_pc", "kdpm_2": "dpm_2", "kdpm_2_a": "dpm_2_ancestral", "dpmpp_2s": "dpmpp_2s_ancestral"}.get(name, name)
    if name not in {"euler", "euler_ancestral", "heun", "dpm_2", "dpm_2_ancestral", "dpmpp_2s_ancestral", "dpmpp_2m", "dpmpp_2m_sde", "dpmpp_sde", "uni_pc", "ddim", "lcm"}:
        raise ValueError("Choose Euler, Heun, DPM++, UniPC, DDIM or LCM for this ComfyUI worker.")
    graph = {
        "1": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": model["key"]}},
        "2": {"class_type": "CLIPTextEncode", "inputs": {"text": job["prompt"], "clip": ["1", 1]}},
        "3": {"class_type": "CLIPTextEncode", "inputs": {"text": job.get("negative_prompt", ""), "clip": ["1", 1]}},
        "4": {"class_type": "EmptyLatentImage", "inputs": {"width": int(job["width"]), "height": int(job["height"]), "batch_size": 1}},
        "5": {"class_type": "KSampler", "inputs": {"model": ["1", 0], "positive": ["2", 0], "negative": ["3", 0],
            "latent_image": ["4", 0], "seed": seed, "steps": int(job.get("steps", 20)), "cfg": float(job.get("cfg_scale", 7)),
            "sampler_name": name, "scheduler": "karras" if karras else "normal", "denoise": 1}},
        "6": {"class_type": "VAEDecode", "inputs": {"samples": ["5", 0], "vae": ["1", 2]}},
        "7": {"class_type": "SaveImage", "inputs": {"images": ["6", 0], "filename_prefix": "Vaultcast/image"}},
    }
    if job.get("tiled", True):
        graph["6"]["class_type"] = "VAEDecodeTiled"
        graph["6"]["inputs"].update(tile_size=512, overlap=64, temporal_size=64, temporal_overlap=8)
    return graph


def video_graph(job, start_image=None):
    if not job.get("id"):
        raise ValueError("Videos must be generated from a claimed Vaultcast job.")
    seed = int(job["seed"]) if job.get("seed") is not None else int(hashlib.sha256(str(job["id"]).encode()).hexdigest()[:12], 16)
    graph = {
        "1": {"class_type": "UNETLoader", "inputs": {"unet_name": "wan2.2_ti2v_5B_fp16.safetensors", "weight_dtype": "default"}},
        "2": {"class_type": "CLIPLoader", "inputs": {"clip_name": "umt5_xxl_fp8_e4m3fn_scaled.safetensors", "type": "wan", "device": "default"}},
        "3": {"class_type": "VAELoader", "inputs": {"vae_name": "wan2.2_vae.safetensors"}},
        "4": {"class_type": "CLIPTextEncode", "inputs": {"text": job["prompt"], "clip": ["2", 0]}},
        "5": {"class_type": "CLIPTextEncode", "inputs": {"text": job.get("negative_prompt", ""), "clip": ["2", 0]}},
        "6": {"class_type": "Wan22ImageToVideoLatent", "inputs": {"width": int(job.get("width", 512)), "height": int(job.get("height", 320)),
            "length": int(job.get("frames", 49)), "batch_size": 1, "vae": ["3", 0]}},
        "7": {"class_type": "ModelSamplingSD3", "inputs": {"model": ["1", 0], "shift": 8}},
        "8": {"class_type": "KSampler", "inputs": {"model": ["7", 0], "positive": ["4", 0], "negative": ["5", 0], "latent_image": ["6", 0],
            "seed": seed, "steps": int(job.get("steps", 20)), "cfg": 5, "sampler_name": "uni_pc", "scheduler": "simple", "denoise": 1}},
        "9": {"class_type": "VAEDecode", "inputs": {"samples": ["8", 0], "vae": ["3", 0]}},
        "10": {"class_type": "CreateVideo", "inputs": {"images": ["9", 0], "fps": int(job.get("fps", 24))}},
        "11": {"class_type": "SaveVideo", "inputs": {"video": ["10", 0], "filename_prefix": "Vaultcast/video", "format": "mp4", "format.codec": "h264", "format.codec.encoding": "auto"}},
    }
    if start_image:
        graph["12"] = {"class_type": "LoadImage", "inputs": {"image": start_image}}
        graph["6"]["inputs"]["start_image"] = ["12", 0]
    return graph
