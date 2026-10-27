"""Optional Vaultcast contributor for InvokeAI 6.x (Windows, Python 3.10+)."""
import getpass
import json
import os
import random
import socket
import sys
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
import uuid

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
    with urllib.request.urlopen(req, timeout=timeout) as res:
        raw = res.read()
        return json.loads(raw.decode()) if raw else {}


def load_config():
    os.makedirs(CONFIG_DIR, exist_ok=True)
    if os.path.exists(CONFIG_PATH):
        with open(CONFIG_PATH, encoding="utf-8") as f:
            return json.load(f)
    print("Vaultcast AI contributor setup")
    server = input("Vaultcast URL (for example https://vaultcast.example.com): ").strip().rstrip("/")
    code = input("One-time pairing code from AI → Images: ").strip().lower()
    if not server.startswith(("http://", "https://")):
        raise RuntimeError("Enter a full Vaultcast URL beginning with http:// or https://")
    models = request(INVOKE + "/api/v2/models/")
    records = models.get("models", models.get("items", models.get("data", [])))
    sdxl = [m for m in records if str(m.get("base", "")).lower() == "sdxl" and str(m.get("type", "main")).lower() == "main"]
    if not sdxl:
        raise RuntimeError("InvokeAI has no installed SDXL main model. Install one in InvokeAI, then run this worker again.")
    model = sdxl[0]
    key = str(model.get("key", ""))
    if not key:
        raise RuntimeError("InvokeAI did not provide a model key.")
    try:
        model = request(INVOKE + "/api/v2/models/" + urllib.parse.quote(key, safe=""))
    except Exception:
        pass
    model_name = str(model.get("name") or sdxl[0].get("name") or key)
    register = request(server + "/api/ai/worker/register", "POST", {
        "pair_code": code, "name": socket.gethostname(), "model_name": model_name, "model_base": "sdxl"
    })
    config = {"server": server, "worker_token": register["worker_token"], "worker_id": register["worker_id"],
              "model": model, "invoke": INVOKE, "model_name": model_name}
    with open(CONFIG_PATH, "w", encoding="utf-8") as f:
        json.dump(config, f)
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
        ids["decode"]: {"id": ids["decode"], "type": "l2i", "fp32": bool(job.get("fp32", False)), "tiled": bool(job.get("tiled", False)), "is_intermediate": False, "use_cache": False},
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


def multipart(url, token, filename, content):
    boundary = "----Vaultcast" + uuid.uuid4().hex
    body = ("--" + boundary + "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" + os.path.basename(filename) + "\"\r\nContent-Type: image/png\r\n\r\n").encode() + content + ("\r\n--" + boundary + "--\r\n").encode()
    req = urllib.request.Request(url, data=body, headers={"X-Vaultcast-Worker": token, "Content-Type": "multipart/form-data; boundary=" + boundary}, method="POST")
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
            request(cfg["server"] + "/api/ai/worker/jobs/" + job_id + "/progress", "POST", {"progress": percent}, {"X-Vaultcast-Worker": cfg["worker_token"]}, timeout=15)
        except Exception as exc:
            print("Could not send generation progress to Vaultcast:", exc)

    try:
        client.connect(cfg["invoke"], socketio_path="ws/socket.io", wait_timeout=8)
        return client, progress
    except Exception as exc:
        print("Live InvokeAI progress could not connect; the job will still run:", exc)
        return None, progress


def run_job(cfg, job):
    token = cfg["worker_token"]
    headers = {"X-Vaultcast-Worker": token}
    print("Generating:", job["prompt"][:100])
    graph = graph_for(job, cfg["model"])
    enqueued = request(cfg["invoke"] + "/api/v1/queue/default/enqueue_batch", "POST", {"batch": {"graph": graph, "runs": 1, "origin": "vaultcast"}})
    item_id = (enqueued.get("item_ids") or [None])[0]
    if not item_id:
        raise RuntimeError("InvokeAI did not return a queue item ID.")
    progress_client, current_progress = start_progress_listener(cfg, job["id"], item_id)
    last_update = 0
    try:
        while True:
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
                multipart(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/complete", token, image_name, data)
                print("Saved to the Vaultcast picture library.")
                return
            # Keep the queue lease alive while InvokeAI loads models or decodes the image.
            if time.time() - last_update >= 30:
                try:
                    request(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/progress", "POST", {"progress": current_progress[0]}, headers)
                    last_update = time.time()
                except Exception:
                    pass
            time.sleep(3)
    finally:
        if progress_client is not None and progress_client.connected:
            progress_client.disconnect()


def main():
    cfg = load_config()
    token = cfg["worker_token"]
    if os.name == "nt":
        print("VRAM handling is managed by your InvokeAI install; this worker does not change it.")
        print("InvokeAI recommends partial loading (enabled by default) and, on Windows NVIDIA systems, setting CUDA - Sysmem Fallback Policy to Prefer No Sysmem Fallback in NVIDIA Control Panel.")
    print("Worker online for", cfg.get("model_name", "SDXL"), "— press Ctrl+C to stop.")
    while True:
        try:
            request(cfg["server"] + "/api/ai/worker/heartbeat", "POST", {"ready": True, "model_name": cfg.get("model_name"), "model_base": "sdxl"}, {"X-Vaultcast-Worker": token}, timeout=15)
            result = request(cfg["server"] + "/api/ai/worker/claim", "POST", {}, {"X-Vaultcast-Worker": token}, timeout=30)
            job = result.get("job")
            if job:
                try:
                    run_job(cfg, job)
                except Exception as exc:
                    print("Job failed:", exc)
                    traceback.print_exc()
                    try:
                        request(cfg["server"] + "/api/ai/worker/jobs/" + job["id"] + "/fail", "POST", {"error": str(exc)}, {"X-Vaultcast-Worker": token})
                    except Exception:
                        pass
            else:
                time.sleep(5)
        except KeyboardInterrupt:
            break
        except Exception as exc:
            print("Connection/generation error:", exc)
            time.sleep(10)
    print("Worker stopped.")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("Setup error:", e)
        input("Press Enter to close.")
