"""Claim Vaultcast video jobs; generate under the shared GPU lock and resume uploads."""
import hashlib
import logging
import json
from pathlib import Path
import time
import urllib.request
import urllib.error

from comfy_backend import Engine, ROOT, video_graph, stopped, download
import worker
from worker_cache import cache_use
from worker_eta import RateWindow


def run(cfg, stop):
    engine = Engine(cfg)
    while not stop.is_set() and not cfg["_drain_event"].is_set():
        try:
            worker.slot_status(cfg, "Waiting for GPU / Vaultcast video job")
            with cfg["_gpu_coordinator"].reserve(cfg):
                result = worker.request(cfg["server"] + "/api/ai/worker/video/claim", "POST", {}, worker.worker_headers(cfg))
                job = result.get("job")
                if job:
                    generate(cfg, job, engine, stop)
            if not job:
                stop.wait(5)
        except (InterruptedError, KeyboardInterrupt):
            return
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise
            worker.slot_status(cfg, "Video worker retry: " + str(error))
            stop.wait(10)
        except Exception as error:
            worker.slot_status(cfg, "Video worker retry: " + str(error))
            stop.wait(10)


@cache_use
def generate(cfg, job, engine, stop):
    base = cfg["server"] + "/api/ai/worker/video/jobs/" + job["id"]
    headers = dict(worker.worker_headers(cfg), **{"X-Vaultcast-Lease": job["lease"]})
    last = [0]
    eta_window = RateWindow()
    def progress(value, message, **metrics):
        if metrics.get("phase") in ("download", "upload") and metrics.get("bytes_total"):
            rate, eta = eta_window.measure(time.monotonic(), metrics.get("bytes_done", 0),
                max(0, metrics["bytes_total"]-metrics.get("bytes_done", 0)), (metrics["phase"], metrics["bytes_total"]))
            metrics.update(bytes_per_second=rate, display_eta_seconds=eta)
        worker.slot_status(cfg, message)
        cfg["_console"].set_progress(cfg["slot_id"], dict({"phase_progress": value}, **metrics))
        if time.monotonic() - last[0] >= 25:
            worker.request(base + "/progress", "POST", {"progress": value or 1}, headers)
            last[0] = time.monotonic()
    output = ROOT / "generated-videos" / (hashlib.sha256((cfg["server"] + job["id"]).encode()).hexdigest() + ".mp4")
    if not output.exists():
        try:
            outputs = engine.generate(video_graph(job), cfg["server"] + ":video:" + job["id"], stop, progress)
        except (RuntimeError, ValueError) as error:
            worker.request(base + "/fail", "POST", {"error": str(error)}, headers)
            return
        url, _ = engine.output(outputs, video=True)
        sample = [time.monotonic(), 0]
        def download_progress(_text, done, total):
            now = time.monotonic()
            rate = max(0, done-sample[1]) / max(0.001, now-sample[0])
            sample[:] = [now, done]
            progress(100*done/total if total else None, "Saving generated video locally", phase="download", bytes_per_second=rate, bytes_done=done, bytes_total=total)
        download(url, output, progress=download_progress, cancel=stop)
    size = output.stat().st_size
    if not 0 < size <= 67108864:
        worker.request(base + "/fail", "POST", {"error": "Generated video exceeds the 64 MiB upload limit."}, headers)
        return
    digest = hashlib.sha256()
    with output.open("rb") as source:
        for block in iter(lambda: source.read(2097152), b""):
            stopped(stop)
            digest.update(block)
    # On transport failure the outer loop reclaims this assignment, reuses the output,
    # asks the server for its confirmed offset, and does not regenerate the clip.
    status = worker.request(base + "/status", "POST", {}, headers)
    if status.get("completed"):
        try:
            output.unlink(missing_ok=True)
        except OSError:
            logging.exception("Confirmed video local cleanup failed")
        engine.release_output(cfg["server"] + ":video:" + job["id"])
        return
    offset = int(status["offset"])
    with output.open("rb") as source:
        while offset < size:
            stopped(stop)
            source.seek(offset)
            block = source.read(min(2097152, size-offset))
            request = urllib.request.Request(base + "/chunk", block, dict(headers, **{
                "Content-Type": "application/octet-stream", "X-Vaultcast-Offset": str(offset)}), method="POST")
            started = time.monotonic()
            with urllib.request.urlopen(request, timeout=120) as response:
                result = json.loads(response.read())
            confirmed = int(result["offset"])
            if not 0 <= confirmed <= size or confirmed == offset:
                raise RuntimeError("The video upload made no progress; output is retained for retry.")
            rate = max(0, confirmed-offset) / max(0.001, time.monotonic()-started)
            offset = confirmed
            progress(100*offset/size, "Uploading video", phase="upload", bytes_per_second=rate, bytes_done=offset, bytes_total=size)
    try:
        worker.request(base + "/complete", "POST", {"size": size, "sha256": digest.hexdigest()}, headers, timeout=90)
    except urllib.error.HTTPError as error:
        if error.code == 422:
            worker.request(base + "/fail", "POST", {"error": "Vaultcast rejected the generated video format or integrity check."}, headers)
            return
        raise
    try:
        output.unlink(missing_ok=True)
    except OSError:
        logging.exception("Confirmed video local cleanup failed")
    engine.release_output(cfg["server"] + ":video:" + job["id"])
    worker.slot_status(cfg, "Video delivered to Vaultcast")
