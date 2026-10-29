"""Local contributor engine setup. Nothing here runs on the Vaultcast web server."""
import csv
import atexit
import ctypes
import hashlib
import json
import os
import pathlib
import platform
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from image_checks import check_image

INVOKE_VERSION = "6.14.2"
ENGINE_URL = "http://127.0.0.1:9090"
DATA = pathlib.Path(os.environ.get("LOCALAPPDATA", os.path.expanduser("~"))) / "VaultcastAIWorker"
ENGINE_STATE = DATA / "engine.json"
SDXL_SOURCE = "https://huggingface.co/stabilityai/stable-diffusion-xl-base-1.0/resolve/main/sd_xl_base_1.0.safetensors"
_managed_process = None


def release_managed_models(request):
    if _managed_process is None or _managed_process.poll() is not None:
        return
    try:
        request(ENGINE_URL + "/api/v2/models/empty_model_cache", "POST", {}, timeout=30)
        print("Released unused model cache from the dedicated contributor engine.")
    except Exception as error:
        print("Could not release the dedicated engine cache:", error)


def record_engine(root, installing=False):
    try: previous = json.loads(ENGINE_STATE.read_text(encoding="utf-8"))
    except (OSError, ValueError): previous = {}
    roots = previous.get("owned_roots") or ([previous["root"]] if previous.get("root") else [])
    roots = list(dict.fromkeys([str(value) for value in roots] + [str(root)]))
    DATA.mkdir(parents=True, exist_ok=True)
    temporary = ENGINE_STATE.with_suffix(".tmp")
    temporary.write_text(json.dumps({"root": str(root), "owned_roots": roots, "version": INVOKE_VERSION, "installing": installing}), encoding="utf-8")
    temporary.replace(ENGINE_STATE)


@atexit.register
def stop_managed_engine():
    if _managed_process is not None and _managed_process.poll() is None:
        # Only the dedicated engine started by this contributor is owned here.
        subprocess.run(["taskkill", "/PID", str(_managed_process.pid), "/T", "/F"], stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), check=False)


def yes(prompt, default=True):
    answer = input(prompt + (" [Y/n]: " if default else " [y/N]: ")).strip().lower()
    return answer in ("y", "yes") or (not answer and default)


def hardware():
    result = {"platform": platform.system(), "architecture": platform.machine(), "ram_gb": None, "gpus": []}
    if os.name == "nt":
        class Memory(ctypes.Structure):
            _fields_ = [("length", ctypes.c_ulong), ("load", ctypes.c_ulong)] + [(name, ctypes.c_ulonglong) for name in
                ("total", "available", "page_total", "page_available", "virtual_total", "virtual_available", "extended")]
        mem = Memory(); mem.length = ctypes.sizeof(mem)
        if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(mem)):
            result["ram_gb"] = round(mem.total / 1073741824, 1)
    smi = shutil.which("nvidia-smi")
    if not smi and os.name == "nt":
        candidate = pathlib.Path(os.environ.get("WINDIR", "C:/Windows")) / "System32/nvidia-smi.exe"
        if candidate.is_file(): smi = str(candidate)
    if smi:
        try:
            output = subprocess.check_output([smi, "--query-gpu=name,memory.total", "--format=csv,noheader,nounits"],
                text=True, timeout=15, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            for row in csv.reader(output.splitlines()):
                result["gpus"].append({"name": row[0].strip(), "vram_mb": int(float(row[1])), "compute_capability": "unknown"})
            try:
                caps = subprocess.check_output([smi, "--query-gpu=compute_cap", "--format=csv,noheader,nounits"], text=True,
                    timeout=15, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0)).splitlines()
                for gpu, cap in zip(result["gpus"], caps): gpu["compute_capability"] = cap.strip()
            except (OSError, subprocess.SubprocessError): pass
        except (OSError, ValueError, subprocess.SubprocessError):
            pass
    return result


def alive(request, url):
    try:
        return isinstance(request(url + "/api/v2/models/", timeout=3).get("models"), list)
    except Exception:
        return False


def engine_roots():
    roots = []
    for config in (ENGINE_STATE, pathlib.Path(os.environ.get("APPDATA", "")) / "Invoke Community Edition/config.json"):
        try:
            data = json.loads(config.read_text(encoding="utf-8"))
            value = data.get("root") or data.get("installDir")
            if value: roots.append(pathlib.Path(value))
        except (OSError, ValueError):
            pass
    roots.append(pathlib.Path.home() / "invokeai")
    return list(dict.fromkeys(roots))


def start_engine(root, request):
    global _managed_process
    executable = root / ".venv/Scripts/invokeai-web.exe"
    if not executable.is_file(): raise RuntimeError("InvokeAI executable is missing in " + str(root))
    DATA.mkdir(parents=True, exist_ok=True)
    env = dict(os.environ, INVOKEAI_ROOT=str(root))
    if (root / ".vaultcast-managed").exists(): env["HF_HOME"] = str(root / "huggingface")
    with (DATA / "invokeai.log").open("ab") as log:
        process = subprocess.Popen([str(executable)], cwd=str(root), env=env, stdout=log, stderr=log,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
    if (root / ".vaultcast-managed").exists(): _managed_process = process
    print("Starting InvokeAI. First startup can take several minutes. Log:", DATA / "invokeai.log")
    for _ in range(180):
        if alive(request, ENGINE_URL): return
        if process.poll() is not None: raise RuntimeError("InvokeAI stopped during startup. See " + str(DATA / "invokeai.log"))
        time.sleep(2)
    raise RuntimeError("InvokeAI did not become ready within six minutes. See " + str(DATA / "invokeai.log"))


def install_engine(info):
    if os.name != "nt" or platform.machine().lower() not in ("amd64", "x86_64"):
        raise RuntimeError("Automatic engine installation currently supports Windows x64. Install InvokeAI separately, start it, and rerun setup.")
    if not info["gpus"]:
        raise RuntimeError("Automatic installation needs an NVIDIA GPU visible to nvidia-smi. Install its NVIDIA driver, or start an existing compatible InvokeAI engine and rerun setup.")
    print("\nA dedicated InvokeAI " + INVOKE_VERSION + " engine can be installed for this contributor.")
    print("Engine packages and GPU libraries use several GB; the starter image model adds about 7 GB.")
    print("Allow at least 25 GB free for installation and download caches. Models and generated files can use more over time.")
    default = DATA / "InvokeAI"
    root = pathlib.Path(os.path.expandvars(input("Engine folder [" + str(default) + "]: ").strip() or str(default))).expanduser().resolve()
    root.mkdir(parents=True, exist_ok=True)
    marker = root / ".vaultcast-managed"
    if any(root.iterdir()) and not marker.exists():
        raise RuntimeError("Choose an empty folder for the managed engine. Existing InvokeAI installs are detected and reused separately.")
    if shutil.disk_usage(root).free < 25 * 1073741824:
        raise RuntimeError("This drive has less than 25 GB free. Rerun setup and choose another engine folder.")
    if info.get("ram_gb") and info["ram_gb"] < 15:
        print("This PC has under 16 GB RAM. SDXL generation may be slow or fail.")
    if not yes("Download and install this contributor engine now?", False):
        raise RuntimeError("Setup stopped before installing the engine.")
    marker.touch()
    record_engine(root, installing=True)
    gpu = info["gpus"][0]
    try: legacy = float(gpu["compute_capability"]) < 7.5
    except (ValueError, KeyError): legacy = True
    backend = "cu126" if legacy else "cu128"
    print("Using CUDA build", backend, "for", gpu["name"], "(existing InvokeAI installs are not upgraded).")
    uv = [sys.executable, "-m", "uv"]
    install_env = dict(os.environ, UV_CACHE_DIR=str(root / ".uv-cache"), UV_PYTHON_INSTALL_DIR=str(root / ".python"))
    subprocess.run(uv + ["python", "install", "3.12"], env=install_env, check=True)
    venv = root / ".venv"
    if not (venv / "Scripts/python.exe").exists():
        subprocess.run(uv + ["venv", "--python", "3.12", "--managed-python", "--allow-existing", str(venv)], env=install_env, check=True)
    subprocess.run(uv + ["pip", "install", "--python", str(venv / "Scripts/python.exe"),
        "--torch-backend=" + backend, "invokeai==" + INVOKE_VERSION,
        "torch==2.7.1+" + backend, "torchvision==0.22.1+" + backend], env=install_env, check=True)
    config = root / "invokeai.yaml"
    if not config.exists():
        config.write_text("schema_version: 4.0.3\nhost: 127.0.0.1\nport: 9090\nenable_partial_loading: true\nkeep_ram_copy_of_weights: false\n", encoding="utf-8")
    DATA.mkdir(parents=True, exist_ok=True)
    record_engine(root)
    return root


def ensure_engine(request, url, info, autostart=False):
    if alive(request, url): return url
    if url.rstrip("/") != ENGINE_URL:
        raise RuntimeError("Your configured InvokeAI endpoint is offline: " + url)
    # Do not start a second backend on an occupied port.
    with socket.socket() as probe:
        if probe.connect_ex(("127.0.0.1", 9090)) == 0:
            raise RuntimeError("Port 9090 is in use but is not responding as InvokeAI. Check the existing service before continuing.")
    for root in engine_roots():
        if (root / ".venv/Scripts/invokeai-web.exe").is_file():
            if autostart or yes("Start the existing InvokeAI install at " + str(root) + "?"):
                start_engine(root, request); return ENGINE_URL
            raise RuntimeError("Start InvokeAI manually and rerun the contributor.")
    root = install_engine(info)
    start_engine(root, request)
    return ENGINE_URL


def model_identity(model):
    return hashlib.sha256((str(model["base"]) + "\n" + str(model["hash"]).strip().lower()).encode()).hexdigest()


def installed_models(request, url):
    models = request(url + "/api/v2/models/", timeout=15).get("models", [])
    try:
        missing = {m["key"] for m in request(url + "/api/v2/models/missing", timeout=15).get("models", [])}
    except urllib.error.HTTPError as error:
        if error.code not in (404,405): raise
        missing = set()  # Older InvokeAI versions do not expose the missing-files endpoint.
    return [m for m in models if isinstance(m,dict) and m.get("base") == "sdxl" and m.get("type") == "main"
        and all(m.get(key) for key in ("key","hash","name")) and m["key"] not in missing]


def model_report(models):
    return [{"name":m["name"],"hash":str(m["hash"]).strip().lower(),"base":m["base"],"type":m["type"]} for m in models]


def select_model(request, url, preferred=None, choose=False):
    def installed(): return installed_models(request, url)
    models = installed()
    if not models:
        print("\nNo SDXL image model is installed. The starter model is Stability AI SDXL Base 1.0 (~7 GB).")
        print("Model details/license: https://huggingface.co/stabilityai/stable-diffusion-xl-base-1.0")
        if not yes("Download this starter model now?", False): raise RuntimeError("Install an SDXL model in InvokeAI, then rerun setup.")
        source = urllib.parse.quote(SDXL_SOURCE, safe="")
        job = request(url + "/api/v2/models/install?source=" + source, "POST", {})
        install_id = job["id"]
        while True:
            state = request(url + "/api/v2/models/install/" + str(install_id))
            status = state.get("status")
            if status == "completed": break
            if status in ("error", "failed", "canceled"):
                raise RuntimeError("Starter model installation failed: " + str(state.get("error_reason") or status))
            total, done = state.get("total_bytes") or 0, state.get("bytes") or 0
            print("Model install:", status, (str(round(done / total * 100)) + "%") if total else "preparing download", flush=True)
            time.sleep(10)
        models = installed()
    if not models: raise RuntimeError("The model installation completed but InvokeAI has not registered an SDXL main model.")
    if preferred and not choose:
        for model in models:
            if model.get("key") == preferred: return model
    if len(models) == 1: return models[0]
    print("\nChoose a model for the capability check. All installed SDXL models will be offered to Vaultcast:")
    for index, model in enumerate(models, 1): print(str(index) + ". " + model["name"])
    while True:
        try:
            selection = int(input("Model number [1]: ").strip() or "1")
            if 1 <= selection <= len(models): return models[selection - 1]
        except ValueError: pass
        print("Enter one of the model numbers above.")


def generation_check(request, url, model, graph_builder):
    print("\nContributor capability check: one neutral SDXL image, including final decode and download.")
    print("This uses your GPU and saves an image in InvokeAI. Video workers start after contributor setup finishes.")
    if not yes("Run the image check now?"):
        raise RuntimeError("A successful image check is required before this computer accepts jobs. Rerun setup when ready.")
    q = request(url + "/api/v1/queue/default/status").get("queue", {})
    if q.get("pending") or q.get("in_progress"):
        raise RuntimeError("InvokeAI is busy. Let its current queue finish, then rerun the contributor check.")
    for size in (1024, 512):
        start = time.monotonic()
        job = {"prompt": "A red ceramic mug on a wooden table, soft daylight, still life", "negative_prompt": "", "width": size, "height": size, "steps": 8, "seed": 12345}
        batch = request(url + "/api/v1/queue/default/enqueue_batch", "POST", {"batch": {"graph": graph_builder(job, model), "runs": 1, "origin": "vaultcast-capability-check"}})
        item_id = batch["item_ids"][0]
        try:
            while time.monotonic() - start < 1800:
                item = request(url + "/api/v1/queue/default/i/" + str(item_id))
                status = item.get("status")
                if status in ("failed", "canceled"):
                    raise RuntimeError(item.get("error_message") or str((item.get("session") or {}).get("errors") or status))
                if status == "completed":
                    results = (item.get("session") or {}).get("results") or {}
                    image_name = next((v["image"]["image_name"] for v in results.values() if isinstance(v, dict) and isinstance(v.get("image"), dict) and v["image"].get("image_name")), None)
                    if not image_name: raise RuntimeError("The image check finished without returning an image.")
                    with urllib.request.urlopen(url + "/api/v1/images/i/" + urllib.parse.quote(image_name, safe="") + "/full", timeout=60) as response:
                        content = response.read(25 * 1048576 + 1)
                    if not content.startswith((b"\x89PNG\r\n\x1a\n", b"\xff\xd8\xff", b"RIFF")) or len(content) > 25 * 1048576:
                        raise RuntimeError("InvokeAI did not return a valid-sized image file.")
                    check_image(content, capability=True)
                    seconds = round(time.monotonic() - start, 1)
                    print("Passed:", str(size) + "x" + str(size), "in", seconds, "seconds.")
                    return {"images": {"sdxl": {"verified": True, "validation_version": 2, "max_pixels": size * size, "checked_at": int(time.time()), "seconds": seconds, "model_hash": model.get("hash", "")}}, "video": {"verified": False}}
                print("Checking", str(size) + "x" + str(size), "-", status, flush=True)
                time.sleep(5)
            raise RuntimeError("Image check timed out after 30 minutes.")
        except (Exception, KeyboardInterrupt) as exc:
            try: request(url + "/api/v1/queue/default/i/" + str(item_id) + "/cancel", "PUT", timeout=10)
            except Exception: pass
            if isinstance(exc, KeyboardInterrupt): raise
            print("Image check failed:", exc)
            if size == 1024 and yes("Try the lower-memory 512x512 profile?"):
                try: request(url + "/api/v2/models/empty_model_cache", "POST", {})
                except Exception: pass
                continue
            raise RuntimeError("This computer has not passed an image profile; it will not accept jobs.") from exc


def prepare(config, request, graph_builder, force=False):
    info = hardware()
    print("\nVaultcast contributor -", info["platform"], info.get("ram_gb") or "unknown", "GB RAM")
    for gpu in info["gpus"]: print("GPU:", gpu["name"], "-", gpu["vram_mb"], "MB VRAM")
    url = ensure_engine(request, config.get("invoke", ENGINE_URL), info, autostart=config.get("_autostart",False))
    capabilities = config.get("capabilities") or {}
    profile = (capabilities.get("images") or {}).get("sdxl") or {}
    if not force and profile.get("verified") and not installed_models(request,url):
        print("No SDXL image models available. The image worker will wait for a model to be installed.")
        return {"invoke":url,"model":None,"model_name":"","models":[],"hardware":info,"capabilities":capabilities}
    model = select_model(request, url, (config.get("model") or {}).get("key"), choose=force)
    version = request(url + "/api/v1/app/version").get("version", "unknown")
    capabilities = config.get("capabilities") or {}
    profile = (capabilities.get("images") or {}).get("sdxl") or {}
    if force or profile.get("validation_version") != 2 or not profile.get("verified") or profile.get("model_hash") != model.get("hash") or config.get("invoke_version") != version or config.get("hardware") != info:
        try:
            capabilities = generation_check(request, url, model, graph_builder)
        finally:
            release_managed_models(request)
    else:
        release_managed_models(request)
    return {"invoke": url, "model": model, "model_name": model["name"], "models":installed_models(request,url), "invoke_version": version, "hardware": info, "capabilities": capabilities}
