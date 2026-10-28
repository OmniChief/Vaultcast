"""Optional Vaultcast image and video-encoding contributor (Windows, Python 3.10+)."""
import getpass
import json
import logging
import os
from pathlib import Path
import random
import re
import socket
import threading
import sys
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
import uuid
from contributor_setup import prepare, hardware, yes, release_managed_models
from encoding_worker import prepare_encoding, run_encoding, pending_encodings, retryable_upload
from image_checks import check_image, BlankGenerationError

APPDATA = os.environ.get("APPDATA", os.path.expanduser("~"))
CONFIG_DIR = os.path.join(APPDATA, "VaultcastAIWorker")
CONFIG_PATH = os.path.join(CONFIG_DIR, "worker.json")
INVOKE = "http://127.0.0.1:9090"


def request(url, method="GET", data=None, headers=None, timeout=30):
    payload = json.dumps(data).encode() if data is not None else None
    hdr = {"Accept": "application/json"}
    if payload is not None:
        hdr["Content-Type"] = "application/json"
    if headers:
        hdr.update(headers)
    req = urllib.request.Request(url, data=payload, headers=hdr, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            raw = res.read()
            return json.loads(raw.decode()) if raw else {}
    except urllib.error.HTTPError as exc:
        try:
            body = json.loads(exc.read().decode())
            message = body.get("error") or body.get("detail")
            if isinstance(message, list):
                message = "; ".join(".".join(str(part) for part in error.get("loc", [])) + ": " + str(error.get("msg", "Invalid value")) for error in message if isinstance(error, dict))
            if isinstance(message, str):
                endpoint = urllib.parse.urlsplit(url).path
                exc.msg = message + " (" + method + " " + endpoint + ")"
        except (ValueError, AttributeError):
            pass
        raise


def save_config(config):
    temporary = CONFIG_PATH + ".tmp"
    with open(temporary, "w", encoding="utf-8") as f:
        json.dump(config, f)
    os.replace(temporary, CONFIG_PATH)


def ask_number(label, minimum, maximum, default):
    while True:
        try:
            answer = int(input(label + " [" + str(default) + "]: ").strip() or default)
            if minimum <= answer <= maximum: return answer
        except ValueError: pass
        print("Choose a whole number between", minimum, "and", maximum)


def available_cpu_cores():
    if os.name == "nt":
        import ctypes
        kernel = ctypes.windll.kernel32
        kernel.GetCurrentProcess.restype = ctypes.c_void_p
        kernel.GetProcessAffinityMask.argtypes = [ctypes.c_void_p, ctypes.POINTER(ctypes.c_size_t), ctypes.POINTER(ctypes.c_size_t)]
        process_mask, system_mask = ctypes.c_size_t(), ctypes.c_size_t()
        if kernel.GetProcessAffinityMask(kernel.GetCurrentProcess(), ctypes.byref(process_mask), ctypes.byref(system_mask)):
            return [index for index in range(ctypes.sizeof(process_mask) * 8) if process_mask.value & (1 << index)]
    if hasattr(os, "sched_getaffinity"): return sorted(os.sched_getaffinity(0))
    return list(range(os.cpu_count() or 1))


def choose_slots(modes):
    available = available_cpu_cores()
    result = []
    if modes.get("images"):
        result.append({"id":1,"images":True,"encoding":False,"cpu_cores":[]})
        print("One image worker will use InvokeAI's GPU queue.")
    if modes.get("encoding"):
        count = ask_number("How many conversion workers", 1, min(32-len(result),len(available)), 1)
        print("CPU cores below are logical processors allocated to FFmpeg; leave some free for gaming if wanted.")
        used = 0
        for index in range(count):
            number = len(result) + 1
            remaining = len(available) - used
            maximum = remaining - (count-index-1)
            amount = ask_number("CPU cores for conversion worker " + str(index+1) + " (" + str(remaining) + " unassigned)",1,maximum,min(2,maximum))
            cores = available[used:used+amount]; used += amount
            result.append({"id":number,"images":False,"encoding":True,"cpu_cores":cores})
    return result


def show_slots(slots):
    for slot in slots:
        kinds = (['images'] if slot.get('images') else []) + (['encoding'] if slot.get('encoding') else [])
        print("Worker", slot["id"], ":", " + ".join(kinds), "|", len(slot.get("cpu_cores", [])), "encoding CPU cores")


def worker_headers(cfg):
    return {"X-Vaultcast-Worker": cfg["worker_token"], "X-Vaultcast-Slot": str(cfg.get("slot_id", 0))}


def slot_label(cfg):
    return "[Worker " + str(cfg.get("slot_id", 1)) + "]"


def active_capabilities(cfg):
    caps = dict(cfg.get("capabilities") or {})
    if not cfg["contributions"].get("images"): caps["images"] = {}
    if not cfg["contributions"].get("encoding"): caps["encoding"] = {"verified": False}
    return caps


def load_config():
    os.makedirs(CONFIG_DIR, exist_ok=True)
    config = {}
    if os.path.exists(CONFIG_PATH):
        with open(CONFIG_PATH, encoding="utf-8") as f:
            config = json.load(f)
    if "--pair" in sys.argv:
        config.pop("worker_token", None)
        config.pop("worker_id", None)
    force = "--retest" in sys.argv
    has_previous = bool(config.get("contributions"))
    reuse = yes("Use previous configuration?", True) if has_previous else False
    info = hardware()
    modes = dict(config.get("contributions") or {})
    if not reuse:
        print("\nChoose what this computer will contribute for this run.")
        capable = any(g.get("vram_mb", 0) >= 6144 for g in info.get("gpus", [])) and (info.get("ram_gb") or 16) >= 12
        capable = capable or bool((config.get("capabilities", {}).get("images", {}).get("sdxl") or {}).get("verified"))
        if capable:
            choice = ask_number("Contribute: 1=AI images, 2=video encoding, 3=both", 1, 3, 3)
            modes = {"images": choice in (1, 3), "encoding": choice in (2, 3)}
        else:
            print("No supported NVIDIA image hardware detected. Video encoding can still use the CPU.")
            if not yes("Contribute video encoding?"): raise RuntimeError("No contribution selected.")
            modes = {"images": False, "encoding": True}
        config["slots"] = choose_slots(modes)
    elif not config.get("slots"):
        cores = available_cpu_cores()[:max(1, int(config.get("encoding_threads", 1)))] if modes.get("encoding") else []
        config["slots"] = [{"id": 1, **modes, "cpu_cores": cores}]
    modes = {kind: any(slot.get(kind) for slot in config["slots"]) for kind in ("images", "encoding")}
    if modes["encoding"]:
        config["encoding_threads"] = max(len(slot.get("cpu_cores", [])) for slot in config["slots"] if slot.get("encoding"))
    show_slots(config["slots"])
    config["hardware"] = info
    if modes.get("images"):
        try:
            config.update(prepare(dict(config, _autostart=reuse), request, graph_for, force=force))
        except Exception as exc:
            print("AI image setup failed:", exc)
            if not modes.get("encoding") or not yes("Continue as an encoding-only contributor?"):
                raise
            modes["images"] = False
    caps = config.setdefault("capabilities", {})
    if not modes.get("images"):
        config.setdefault("model_name", "")
    if modes.get("encoding"):
        print("Encoding uses your own Vaultcast account. Only videos your account can access are assigned to this computer.")
        settings = prepare_encoding(config, force=force)
        caps["encoding"] = settings.pop("encoding_profile")
        config.update(settings)
    else:
        caps.setdefault("encoding", {"verified": False})
    for slot in config["slots"]:
        if not modes.get("images"): slot["images"] = False
    config["slots"] = [slot for slot in config["slots"] if slot.get("images") or slot.get("encoding")]
    config["contributions"] = modes
    save_config(config)
    if config.get("worker_token"):
        return config
    print("\nContributor ready. Pair this computer with Vaultcast.")
    print("Vaultcast receives this computer's name, GPU/RAM summary, and checked contribution profiles.")
    server = (input("Vaultcast URL (for example https://vaultcast.example.com) [" + config.get("server", "") + "]: ").strip() or config.get("server", "")).rstrip("/")
    if not server.startswith(("http://", "https://")):
        raise RuntimeError("Enter a full Vaultcast URL beginning with http:// or https://")
    config["server"] = server
    save_config(config)
    model_name = config["model_name"]
    print("Use the 32-character ONE-TIME PAIRING CODE from AI -> Images -> Pair a computer, not an account/device API key.")
    print("Each computer needs a fresh code; a code expires after 10 minutes and can only be used once.")
    while True:
        code = "".join(input("One-time pairing code (or Q to stop): ").split()).lower()
        if code == "q": raise RuntimeError("Pairing canceled. Setup is saved; rerun the worker to continue.")
        if not re.fullmatch(r"[a-f0-9]{32}", code):
            print("That is not a pairing code. Generate a fresh code using Pair a computer in AI -> Images.")
            continue
        try:
            register = request(server + "/api/ai/worker/register", "POST", {
                "pair_code": code, "name": socket.gethostname(), "model_name": model_name, "model_base": "sdxl" if modes.get("images") else "", "capabilities": active_capabilities(config), "hardware": config["hardware"], "invoke_version": config.get("invoke_version", "")
            })
            break
        except urllib.error.HTTPError as exc:
            if exc.code not in (400, 403, 422): raise
            print("Pairing rejected:", exc)
            print("Setup is saved. Correct the issue above and enter a fresh code, or Q to stop.")
    config.update({"server": server, "worker_token": register["worker_token"], "worker_id": register["worker_id"]})
    save_config(config)
    print("Paired successfully. Credential saved in", CONFIG_PATH)
    return config


def edge(src, src_field, dst, dst_field):
    return {"source": {"node_id": src, "field": src_field}, "destination": {"node_id": dst, "field": dst_field}}


def graph_for(job, model):
    """Build the simple SDXL text-to-image graph accepted by InvokeAI's executable-graph API."""
    ids = {name: name + "-" + uuid.uuid4().hex[:8] for name in ("loader", "pos", "neg", "pc", "nc", "noise", "denoise", "decode")}
    width, height = int(job["width"]), int(job["height"])
    seed = job.get("seed")
    if seed is None:
        seed = random.randint(0, 4294967295)
    model_id = {k: model[k] for k in ("key", "hash", "name", "base", "type") if k in model}
    if not all(k in model_id for k in ("key", "hash", "name", "base", "type")):
        raise RuntimeError("InvokeAI model details are incomplete (need key, hash, name, base and type). Re-pair after updating InvokeAI.")
    nodes = {
        ids["loader"]: {"id": ids["loader"], "type": "sdxl_model_loader", "model": model_id, "is_intermediate": True, "use_cache": True},
        ids["pos"]: {"id": ids["pos"], "type": "string", "value": job["prompt"], "is_intermediate": True, "use_cache": True},
        ids["neg"]: {"id": ids["neg"], "type": "string", "value": job.get("negative_prompt", ""), "is_intermediate": True, "use_cache": True},
        ids["pc"]: {"id": ids["pc"], "type": "sdxl_compel_prompt", "original_width": width, "original_height": height, "target_width": width, "target_height": height, "is_intermediate": True, "use_cache": True},
        ids["nc"]: {"id": ids["nc"], "type": "sdxl_compel_prompt", "original_width": width, "original_height": height, "target_width": width, "target_height": height, "is_intermediate": True, "use_cache": True},
        ids["noise"]: {"id": ids["noise"], "type": "noise", "width": width, "height": height, "seed": int(seed), "use_cpu": False, "is_intermediate": True, "use_cache": False},
        ids["denoise"]: {"id": ids["denoise"], "type": "denoise_latents", "steps": int(job.get("steps", 30)), "cfg_scale": float(job.get("cfg_scale", 7)), "scheduler": job.get("scheduler", "euler_a"), "denoising_start": 0.0, "denoising_end": 1.0, "hidiffusion": bool(job.get("hidiffusion", False)), "hidiffusion_raunet": bool(job.get("hidiffusion_raunet", True)), "hidiffusion_window_attn": bool(job.get("hidiffusion_window_attn", True)), "hidiffusion_t1_ratio": float(job.get("hidiffusion_t1_ratio", 0.4)), "hidiffusion_t2_ratio": float(job.get("hidiffusion_t2_ratio", 0.0)), "is_intermediate": True, "use_cache": False},
        ids["decode"]: {"id": ids["decode"], "type": "l2i", "fp32": bool(job.get("fp32", True)), "tiled": bool(job.get("tiled", True)), "is_intermediate": False, "use_cache": False},
    }
    seamless = bool(job.get("seamless_x", False) or job.get("seamless_y", False))
    if seamless:
        ids["seamless"] = "seamless-" + uuid.uuid4().hex[:8]
        nodes[ids["seamless"]] = {"id": ids["seamless"], "type": "seamless", "seamless_x": bool(job.get("seamless_x", False)), "seamless_y": bool(job.get("seamless_y", False)), "is_intermediate": True, "use_cache": True}
        unet_node, unet_field = ids["seamless"], "unet"
        vae_node, vae_field = ids["seamless"], "vae"
    else:
        unet_node, unet_field = ids["loader"], "unet"
        vae_node, vae_field = ids["loader"], "vae"
    edges = [
        edge(ids["loader"], "unet", ids["seamless"], "unet") if seamless else edge(ids["loader"], "unet", ids["denoise"], "unet"),
        edge(ids["loader"], "vae", ids["seamless"], "vae") if seamless else edge(ids["loader"], "vae", ids["decode"], "vae"),
        edge(ids["loader"], "clip", ids["pc"], "clip"), edge(ids["loader"], "clip2", ids["pc"], "clip2"),
        edge(ids["loader"], "clip", ids["nc"], "clip"), edge(ids["loader"], "clip2", ids["nc"], "clip2"),
        edge(ids["pos"], "value", ids["pc"], "prompt"), edge(ids["pos"], "value", ids["pc"], "style"),
        edge(ids["neg"], "value", ids["nc"], "prompt"), edge(ids["neg"], "value", ids["nc"], "style"),
        edge(ids["pc"], "conditioning", ids["denoise"], "positive_conditioning"),
        edge(ids["nc"], "conditioning", ids["denoise"], "negative_conditioning"),
        edge(ids["noise"], "noise", ids["denoise"], "noise"),
        edge(ids["denoise"], "latents", ids["decode"], "latents"),
    ]
    if seamless:
        edges.extend([edge(unet_node, unet_field, ids["denoise"], "unet"), edge(vae_node, vae_field, ids["decode"], "vae")])
    return {"id": "vaultcast-" + uuid.uuid4().hex, "nodes": nodes, "edges": edges}


def multipart(url, token, filename, content, slot=0):
    boundary = "----Vaultcast" + uuid.uuid4().hex
    body = ("--" + boundary + "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" + os.path.basename(filename) + "\"\r\nContent-Type: image/png\r\n\r\n").encode() + content + ("\r\n--" + boundary + "--\r\n").encode()
    req = urllib.request.Request(url, data=body, headers={"X-Vaultcast-Worker": token, "Content-Type": "multipart/form-data; boundary=" + boundary, "X-Vaultcast-Slot": str(slot)}, method="POST")
    with urllib.request.urlopen(req, timeout=180) as res:
        return json.loads(res.read().decode())


def start_progress_listener(cfg, job_id, invoke_item_id):
    progress = [1]
    try:
        import socketio
    except ImportError:
        print("Live progress is unavailable (python-socketio is not installed). The job will still run.")
        return None, progress

    client = socketio.Client(reconnection=False, logger=False, engineio_logger=False)

    @client.on("connect")
    def subscribe_to_queue():
        client.emit("subscribe_queue", {"queue_id": "default"})

    @client.on("invocation_progress")
    def receive_progress(event):
        if not isinstance(event, dict) or str(event.get("item_id")) != str(invoke_item_id):
            return
        invocation = event.get("invocation")
        kind = invocation.get("type", "") if isinstance(invocation, dict) else ""
        fraction = event.get("percentage")
        if not isinstance(fraction, (int, float)):
            return
        if kind == "denoise_latents":
            percent = 5 + int(max(0, min(1, fraction)) * 85)
        elif kind in ("l2i", "sdxl_l2i"):
            percent = 90 + int(max(0, min(1, fraction)) * 8)
        else:
            return
        percent = max(progress[0], min(99, percent))
        if percent == progress[0]:
            return
        progress[0] = percent
        try:
            request(cfg["server"] + "/api/ai/worker/jobs/" + job_id + "/progress", "POST", {"progress": percent}, worker_headers(cfg), timeout=15)
        except Exception as exc:
            print("Could not send generation progress to Vaultcast:", exc)

    failures = []
    for transports in (["websocket"], ["polling"]):
        try:
            client.connect(cfg["invoke"], socketio_path="ws/socket.io", transports=transports, wait_timeout=8)
            return client, progress
        except Exception as exc:
            failures.append(str(exc))
            try: client.disconnect()
            except Exception: pass
    logging.warning("InvokeAI progress connection failed: %s", "; ".join(failures))
    print("Live InvokeAI step progress unavailable; using queue-stage polling.")
    return None, progress


def run_job(cfg, job):
    token = cfg["worker_token"]
    headers = worker_headers(cfg)
    print(slot_label(cfg), "Generating:", job["prompt"][:100])
    graph = graph_for(job, cfg["model"])
    enqueued = request(cfg["invoke"] + "/api/v1/queue/default/enqueue_batch", "POST", {"batch": {"graph": graph, "runs": 1, "origin": "vaultcast"}})
    item_id = (enqueued.get("item_ids") or [None])[0]
    if not item_id:
        raise RuntimeError("InvokeAI did not return a queue item ID.")
    progress_client, current_progress = start_progress_listener(cfg, job["id"], item_id)
    last_update = 0
    try:
        while True:
            if cfg.get("_stop_event") and cfg["_stop_event"].is_set(): raise KeyboardInterrupt()
            item = request(cfg["invoke"] + "/api/v1/queue/default/i/" + urllib.parse.quote(str(item_id)), headers=headers)
            status = item.get("status")
            if status == "failed":
                raise RuntimeError(item.get("error_message") or "InvokeAI generation failed.")
            if status == "canceled":
                raise RuntimeError("InvokeAI generation was canceled.")
            if status == "completed":
                session = item.get("session") if isinstance(item.get("session"), dict) else {}
                results = session.get("results", {})
                entries = results.values() if isinstance(results, dict) else results if isinstance(results, list) else []
                image_name = None
                for result in entries:
                    if not isinstance(result, dict) or result.get("type") != "image_output":
                        continue
                    image = result.get("image")
                    if isinstance(image, dict):
                        image_name = image.get("image_name")
                    elif isinstance(image, str):
                        image_name = image
                    image_name = image_name or result.get("image_name")
                    if image_name:
                        break
                if not image_name:
                    raise RuntimeError("InvokeAI completed, but its session results contained no image output.")
                image_url = cfg["invoke"] + "/api/v1/images/i/" + urllib.parse.quote(image_name, safe="") + "/full"
                with urllib.request.urlopen(image_url, timeout=180) as res:
                    data = res.read()
                try:
                    check_image(data)
                except BlankGenerationError:
                    if not job.get("_precision_retry") and not (job.get("fp32", True) and job.get("tiled", True)):
                        print("Black image detected. Retrying once with FP32 and tiled VAE decode.")
                        if progress_client is not None and progress_client.connected: progress_client.disconnect()
                        return run_job(cfg, dict(job, fp32=True, tiled=True, _precision_retry=True))
                    raise
                multipart(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/complete", token, image_name, data, cfg.get("slot_id", 0))
                print("Saved to the Vaultcast picture library.")
                return
            if progress_client is None:
                session = item.get("session") if isinstance(item.get("session"), dict) else {}
                nodes = (session.get("execution_graph") or {}).get("nodes") or {}
                results = session.get("results") or {}
                completed = {nodes.get(node, {}).get("type") for node in results} if isinstance(results, dict) and isinstance(nodes, dict) else set()
                stage = 98 if "l2i" in completed else 90 if "denoise_latents" in completed else 5 if completed else 1
                if stage > current_progress[0]: current_progress[0] = stage; last_update = 0
            # Keep the queue lease alive while InvokeAI loads models or decodes the image.
            if time.time() - last_update >= 30:
                try:
                    request(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/progress", "POST", {"progress": current_progress[0]}, headers)
                    last_update = time.time()
                except Exception:
                    pass
            time.sleep(3)
    except KeyboardInterrupt:
        try:
            request(cfg["invoke"] + "/api/v1/queue/default/i/" + str(item_id) + "/cancel", "PUT", timeout=10)
            request(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/fail", "POST", {"error": "The contributor stopped. Please retry this generation."}, headers, timeout=10)
        except Exception:
            pass
        raise
    finally:
        if progress_client is not None and progress_client.connected:
            progress_client.disconnect()
        release_managed_models(request)


_active_conversions = set()
_conversion_lock = threading.Lock()
_image_lock = threading.Lock()


def run_slot(cfg, stop):
    images, encoding = cfg["contributions"].get("images"), cfg["contributions"].get("encoding")
    headers = worker_headers(cfg)
    prefer_encoding = True
    while not stop.is_set():
        try:
            image_ready = images
            if images:
                try: request(cfg["invoke"] + "/api/v1/app/version", timeout=5)
                except Exception: image_ready = False
            with _conversion_lock:
                retained = [job for job in pending_encodings(cfg) if job["id"] not in _active_conversions] if encoding else []
            if encoding and (retained or prefer_encoding or not image_ready):
                result = {"job": retained[0]} if retained else request(cfg["server"] + "/api/ai/worker/encoding/claim", "POST", {}, headers, timeout=15)
                job = result.get("job")
                if job:
                    with _conversion_lock:
                        claimed = job["id"] not in _active_conversions
                        if claimed: _active_conversions.add(job["id"])
                    if not claimed:
                        stop.wait(1)
                        continue
                    try:
                        run_encoding(cfg, job, request)
                    except Exception as error:
                        logging.exception("Conversion/upload paused in slot %s", cfg["slot_id"])
                        print(slot_label(cfg), "Conversion/upload paused:", error)
                        if retryable_upload(error):
                            print(slot_label(cfg), "Files kept. Retrying in 15 seconds.")
                            stop.wait(15)
                    finally:
                        with _conversion_lock: _active_conversions.discard(job["id"])
                    prefer_encoding = False
                    continue
            prefer_encoding = True
            if image_ready and _image_lock.acquire(blocking=False):
                try:
                    result = request(cfg["server"] + "/api/ai/worker/claim", "POST", {}, headers, timeout=15)
                    job = result.get("job")
                    if job:
                        try: run_job(cfg, job)
                        except Exception as error:
                            logging.exception("Image generation failed in slot %s", cfg["slot_id"])
                            print(slot_label(cfg), "Image failed:", error)
                            try: request(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/fail", "POST", {"error": str(error)}, headers)
                            except Exception: pass
                        continue
                finally:
                    _image_lock.release()
            stop.wait(3)
        except KeyboardInterrupt:
            break
        except Exception as error:
            if isinstance(error, urllib.error.HTTPError) and error.code in (401,403): raise
            print(slot_label(cfg), "Connection error:", error)
            stop.wait(10)


def heartbeat(cfg, ready=True):
    images = cfg["contributions"].get("images")
    caps = active_capabilities(cfg)
    if images:
        try: request(cfg["invoke"] + "/api/v1/app/version", timeout=5)
        except Exception: caps["images"] = {}
    return request(cfg["server"] + "/api/ai/worker/heartbeat", "POST", {
        "ready": ready, "model_name": cfg.get("model_name"), "model_base": "sdxl" if images else "",
        "capabilities": caps, "hardware": cfg["hardware"], "invoke_version": cfg.get("invoke_version", ""),
        "slots": cfg["slots"]}, worker_headers(cfg), timeout=15)


def main():
    if "--uninstall" in sys.argv:
        print("Run uninstall-worker.cmd or start-worker.cmd --uninstall to remove this contributor.")
        return
    cfg = load_config()
    stop = threading.Event()
    threads = []
    available = set(available_cpu_cores())
    used = set()
    for slot in cfg["slots"]:
        cores = slot.get("cpu_cores", [])
        if slot.get("encoding") and (not cores or not set(cores).issubset(available) or used.intersection(cores)):
            raise RuntimeError("Saved CPU allocation is unavailable or overlaps. Restart and choose not to reuse previous configuration.")
        used.update(cores)
    response = heartbeat(cfg)
    if response.get("slot_protocol") != 1:
        raise RuntimeError("Update the Vaultcast webapp before starting this multi-worker version.")
    def start_slot(slot):
        local = dict(cfg, slot_id=slot["id"], contributions={"images":slot.get("images",False),"encoding":slot.get("encoding",False)},
            encoding_threads=max(1,len(slot.get("cpu_cores",[]))), cpu_cores=slot.get("cpu_cores",[]), _stop_event=stop,
            parallel_workers=len(cfg["slots"]))
        try: run_slot(local,stop)
        except Exception as error:
            logging.exception("Worker slot stopped")
            print(slot_label(local), "Stopped:", error)
            stop.set()
    print("Starting", len(cfg["slots"]), "workers. Press Ctrl+C to stop all workers.")
    for slot in cfg["slots"]:
        thread = threading.Thread(target=start_slot,args=(slot,),name="worker-"+str(slot["id"]))
        thread.start(); threads.append(thread)
    try:
        while not stop.wait(10) and any(thread.is_alive() for thread in threads):
            try: heartbeat(cfg)
            except Exception as error: print("Contributor heartbeat:",error)
    except KeyboardInterrupt:
        print("Stopping workers; retaining unconfirmed conversion files...")
    finally:
        stop.set()
        for thread in threads:
            while thread.is_alive():
                try: thread.join(timeout=1)
                except KeyboardInterrupt: print("Waiting for active worker operations to stop...")
        try: heartbeat(cfg,False)
        except Exception: pass
    print("All workers stopped.")


if __name__ == "__main__":
    log_path = Path(os.environ.get("USERPROFILE", os.path.expanduser("~"))) / ".vaultcast" / "worker.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(filename=log_path, encoding="utf-8", level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.info("Contributor starting with Python %s", sys.executable)
    print("Worker diagnostic log:", log_path, flush=True)
    try:
        main()
    except Exception as e:
        logging.exception("Contributor setup failed")
        print("Setup error:", e)
        print("Full error saved to:", log_path)
        input("Press Enter to close.")
