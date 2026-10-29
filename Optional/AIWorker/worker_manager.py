"""Windows worker supervision. Generation is exclusively claimed from Vaultcast."""
import copy
import json
import logging
import os
from pathlib import Path
import queue
import sys
import threading
import tkinter as tk
from tkinter import ttk, messagebox, filedialog

import worker
import pairing
from encoding_worker import GpuCoordinator
from worker_console import slot_kind
from worker_version import VERSION


class Tooltip:
    def __init__(self, widget, text):
        self.widget, self.text, self.window = widget, text, None
        widget.bind("<Enter>", self.show, add=True)
        widget.bind("<Leave>", self.hide, add=True)
        widget.bind("<Destroy>", self.hide, add=True)

    def show(self, _event=None):
        if self.window:
            return
        self.window = tk.Toplevel(self.widget)
        self.window.overrideredirect(True)
        self.window.geometry("+%d+%d" % (self.widget.winfo_rootx(), self.widget.winfo_rooty() + 28))
        ttk.Label(self.window, text=self.text, wraplength=340, padding=10).pack()

    def hide(self, _event=None):
        if self.window:
            self.window.destroy()
            self.window = None


class Controller:
    """Own worker threads; communicate with Tk only through a message queue."""
    enabled = True

    def __init__(self, events):
        self.events = events
        self.running = {}
        self.lock = threading.RLock()
        self.heartbeat_lock = threading.Lock()
        self.gpu = GpuCoordinator()
        self.shutdown = threading.Event()
        self.config = {}
        self.pairing = False
        if Path(worker.CONFIG_PATH).exists():
            self.config = json.loads(Path(worker.CONFIG_PATH).read_text(encoding="utf-8"))
        threading.Thread(target=self.heartbeat_loop, daemon=True, name="manager-heartbeat").start()

    def set_status(self, slot, text):
        self.events.put(("status", slot, str(text)))

    def set_progress(self, slot, data):
        self.events.put(("progress", slot, dict(data)))

    def set_limits(self, limits):
        self.events.put(("limits", limits))

    def set_kind(self, slot, kind):
        self.events.put(("kind", slot, kind))

    def log(self, *parts):
        self.events.put(("log", " ".join(map(str, parts))))

    def live_config(self):
        with self.lock:
            cfg = copy.deepcopy(self.config)
            cfg["slots"] = [copy.deepcopy(item[3]) for item in self.running.values() if item[0].is_alive()]
        cfg["contributions"] = {kind: any(s.get(kind) for s in cfg["slots"]) for kind in ("images", "encoding", "video")}
        cfg["_console"] = self
        return cfg

    def start(self, slot):
        with self.lock:
            if self.pairing:
                raise ValueError("Wait for pairing to finish before starting workers.")
            current = self.running.get(slot["id"])
            if current and current[0].is_alive():
                return
            if not self.config.get("worker_token"):
                raise ValueError("Connect this computer to Vaultcast before starting workers.")
            # Validate affinity before FFmpeg can start; a worker owns its assigned cores.
            used = {core for item in self.running.values() if item[0].is_alive() for core in item[3].get("cpu_cores", [])}
            cores = slot.get("cpu_cores", [])
            if slot.get("encoding") and (not cores or not set(cores).issubset(worker.available_cpu_cores()) or used.intersection(cores)):
                raise ValueError("Choose available CPU cores that are not assigned to another running worker.")
            stop, drain = threading.Event(), threading.Event()
            cfg = copy.deepcopy(self.config)
            cfg.update(slot_id=slot["id"], contributions={k: bool(slot.get(k)) for k in ("images", "encoding", "video")},
                       cpu_cores=cores, encoding_threads=max(1, len(cores)),
                       encoding_encoder=slot.get("encoding_encoder", "libx264"), encoding_device=slot.get("encoding_device", 0),
                       _console=self, _gpu_coordinator=self.gpu, _stop_event=stop, _drain_event=drain,
                       _slot_kind=slot_kind(slot), _images_enabled=any(s.get("images") or s.get("video") for s in self.config.get("slots", [])))
            def target():
                try:
                    response = self.send_heartbeat()
                    if response.get("slot_protocol") != 1:
                        raise RuntimeError("Update Vaultcast before starting this worker manager.")
                    worker.run_slot(cfg, stop)
                except BaseException as error:
                    if not isinstance(error, (KeyboardInterrupt, InterruptedError)):
                        logging.exception("Worker stopped")
                        self.set_status(slot["id"], "Stopped: " + str(error))
                finally:
                    self.events.put(("finished", slot["id"]))
            thread = threading.Thread(target=target, daemon=True, name="worker-" + str(slot["id"]))
            self.running[slot["id"]] = (thread, stop, drain, copy.deepcopy(slot))
            thread.start()

    def stop(self, slot_id, finish=True):
        with self.lock:
            item = self.running.get(slot_id)
            if item and item[0].is_alive():
                item[2 if finish else 1].set()
                self.set_status(slot_id, "Finishing current job, then stopping" if finish else "Stopping; retaining conversion checkpoints")

    def send_heartbeat(self):
        with self.heartbeat_lock:
            cfg = self.live_config()
            return worker.heartbeat(cfg, bool(cfg["slots"]))

    def heartbeat_loop(self):
        while not self.shutdown.wait(10):
            try:
                cfg = self.live_config()
                if cfg.get("worker_token"):
                    self.send_heartbeat()
            except Exception as error:
                self.log("Vaultcast connection:", error)


class ScrollPage(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        canvas = tk.Canvas(self, highlightthickness=0, background="#f3f5f8")
        scroll = ttk.Scrollbar(self, orient="vertical", command=canvas.yview)
        canvas.configure(yscrollcommand=scroll.set)
        scroll.pack(side="right", fill="y")
        canvas.pack(side="left", fill="both", expand=True)
        self.body = ttk.Frame(canvas, padding=20)
        window = canvas.create_window((0, 0), window=self.body, anchor="nw")
        canvas.bind("<Configure>", lambda event: canvas.itemconfigure(window, width=event.width))
        self.body.bind("<Configure>", lambda _event: canvas.configure(scrollregion=canvas.bbox("all")))


class Manager(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Vaultcast Worker Manager " + VERSION)
        self.geometry("1080x760")
        self.minsize(850, 600)
        self.events = queue.Queue()
        self.controller = Controller(self.events)
        self.cards = {}
        self.closing = False
        self.pairing_busy = False
        self.setup_busy = False
        self.setup_cancel = threading.Event()
        self.engine = None
        style = ttk.Style(self)
        style.theme_use("clam")
        style.configure("TFrame", background="#f3f5f8")
        style.configure("TLabel", background="#f3f5f8", foreground="#18243a", font=("Segoe UI", 10))
        style.configure("Title.TLabel", font=("Segoe UI", 22, "bold"))
        style.configure("TButton", padding=(12, 8), font=("Segoe UI", 10))
        header = ttk.Frame(self, padding=24)
        header.pack(fill="x")
        ttk.Label(header, text="Vaultcast workers", style="Title.TLabel").pack(side="left")
        ttk.Label(header, text="Version " + VERSION).pack(side="right")
        self.tabs = ttk.Notebook(self)
        self.tabs.pack(fill="both", expand=True, padx=24, pady=(0, 24))
        worker_page, settings_page, setup_page = [ScrollPage(self.tabs) for _ in range(3)]
        self.workers = worker_page.body
        self.settings = settings_page.body
        self.activity = ttk.Frame(self.tabs, padding=20)
        self.setup = setup_page.body
        for tab, title in ((worker_page, "Workers"), (setup_page, "Setup & models"), (settings_page, "Connection & settings"), (self.activity, "Activity")):
            self.tabs.add(tab, text=title)
        self.build_workers()
        self.build_settings()
        self.build_setup()
        self.logs = tk.Text(self.activity, wrap="word", state="disabled", font=("Consolas", 10))
        scrollbar = ttk.Scrollbar(self.activity, command=self.logs.yview)
        self.logs.configure(yscrollcommand=scrollbar.set)
        scrollbar.pack(side="right", fill="y")
        self.logs.pack(fill="both", expand=True)
        self.protocol("WM_DELETE_WINDOW", self.close)
        self.after(150, self.poll)

    def button(self, parent, text, command, help_text=None):
        result = ttk.Button(parent, text=text, command=lambda: self.action(command))
        result.pack(side="left", padx=(0, 8))
        if help_text:
            Tooltip(result, help_text)
        return result

    def action(self, command):
        try:
            command()
        except Exception as error:
            messagebox.showerror("Vaultcast", str(error), parent=self)

    def build_workers(self):
        ttk.Label(self.workers, text="Jobs come from Vaultcast. Workers never generate images or videos manually.", wraplength=880).pack(anchor="w", pady=(0, 16))
        actions = ttk.Frame(self.workers)
        actions.pack(fill="x", pady=(0, 16))
        self.button(actions, "Start all", lambda: [self.controller.start(s) for s in self.controller.config.get("slots", [])])
        self.button(actions, "Finish jobs & stop all", lambda: [self.controller.stop(s) for s in self.cards], "Current jobs finish before workers stop claiming more work.")
        slots = self.controller.config.get("slots", [])
        if not slots:
            ttk.Label(self.workers, text="No workers configured. Open Setup & models to add workers, then connect your Vaultcast account.", wraplength=850).pack(anchor="w")
        for slot in slots:
            box = ttk.LabelFrame(self.workers, text="Worker %s · %s" % (slot["id"], slot_kind(slot)), padding=12)
            box.pack(fill="x", pady=(0, 12))
            status = tk.StringVar(value="Stopped")
            ttk.Label(box, textvariable=status, wraplength=830).pack(anchor="w")
            progress = ttk.Progressbar(box, maximum=100)
            progress.pack(fill="x", pady=8)
            row = ttk.Frame(box)
            row.pack(fill="x")
            self.button(row, "Start", lambda s=slot: self.controller.start(s))
            self.button(row, "Finish & stop", lambda i=slot["id"]: self.controller.stop(i))
            self.button(row, "Stop now", lambda i=slot["id"]: self.controller.stop(i, False), "Interrupts the current operation. Conversion checkpoints are kept for resuming; AI generation may restart.")
            self.cards[slot["id"]] = (status, progress)

    def build_settings(self):
        ttk.Label(self.settings, text="Vaultcast connection", style="Title.TLabel").pack(anchor="w", pady=(0, 12))
        ttk.Label(self.settings, text="Changes require all workers to stop, so an active upload keeps its original server.", wraplength=850).pack(anchor="w", pady=(0, 16))
        ttk.Label(self.settings, text="Vaultcast address").pack(anchor="w")
        self.server = tk.StringVar(value=self.controller.config.get("server", ""))
        ttk.Entry(self.settings, textvariable=self.server, width=70).pack(anchor="w", pady=(4, 16))
        row = ttk.Frame(self.settings)
        row.pack(fill="x")
        self.button(row, "Save address", self.save_settings)
        self.button(row, "Open worker files", lambda: os.startfile(str(Path(worker.CONFIG_DIR))))
        self.button(row, "Open logs", lambda: os.startfile(str(Path.home() / ".vaultcast")))
        ttk.Separator(self.settings).pack(fill="x", pady=20)
        ttk.Label(self.settings, text="Pair this computer", font=("Segoe UI", 15, "bold")).pack(anchor="w")
        ttk.Label(self.settings, text="Sign in with your Vaultcast account, or use a one-time pairing code. Your password is never saved.", wraplength=850).pack(anchor="w", pady=8)
        self.username, self.password, self.pair_code = tk.StringVar(), tk.StringVar(), tk.StringVar()
        for title, variable, hidden in (("Vaultcast username", self.username, False), ("Password", self.password, True), ("One-time pairing code (alternative to sign-in)", self.pair_code, True)):
            ttk.Label(self.settings, text=title).pack(anchor="w")
            ttk.Entry(self.settings, textvariable=variable, show="•" if hidden else "", width=56).pack(anchor="w", pady=(3, 8))
        buttons = ttk.Frame(self.settings)
        buttons.pack(fill="x", pady=8)
        self.login_button = self.button(buttons, "Sign in & pair", lambda: self.begin_pair(False))
        self.code_button = self.button(buttons, "Pair with code", lambda: self.begin_pair(True))
        self.pair_status = tk.StringVar(value="Paired" if self.controller.config.get("worker_token") else "Not paired")
        ttk.Label(self.settings, textvariable=self.pair_status, wraplength=850).pack(anchor="w")
        ttk.Label(self.settings, text="Your existing pairing and conversion checkpoints are preserved. This manager does not remove InvokeAI installations or models.", wraplength=850).pack(anchor="w", pady=24)

    def save_settings(self):
        from urllib.parse import urlsplit
        if self.pairing_busy or self.setup_busy:
            raise ValueError("Wait for setup or pairing to finish before saving settings.")
        if any(item[0].is_alive() for item in self.controller.running.values()):
            raise ValueError("Stop all workers before changing the connection.")
        address = self.server.get().strip().rstrip("/")
        parsed = urlsplit(address)
        if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError("Enter a Vaultcast HTTP or HTTPS address without passwords or query parameters.")
        if address != self.controller.config.get("server") and self.controller.config.get("worker_token"):
            raise ValueError("Use Sign in & pair or Pair with code to connect to a different server.")
        Path(worker.CONFIG_DIR).mkdir(parents=True, exist_ok=True)
        self.controller.config["server"] = address
        worker.save_config(self.controller.config)
        messagebox.showinfo("Vaultcast", "Settings saved.", parent=self)

    def build_setup(self):
        from comfy_backend import ROOT
        ttk.Label(self.setup, text="Set up this computer", style="Title.TLabel").pack(anchor="w", pady=(0, 10))
        ttk.Label(self.setup, text="1. Install an AI engine and models if needed.  2. Configure workers.  3. Pair in Connection & settings.", wraplength=880).pack(anchor="w", pady=8)
        form = ttk.Frame(self.setup)
        form.pack(fill="x")
        self.engine_folder = tk.StringVar(value=self.controller.config.get("comfy_root", str(ROOT / "ComfyUI")))
        self.accelerator = tk.StringVar(value="cu128")
        ttk.Label(form, text="AI engine folder").grid(row=0, column=0, sticky="w", pady=8)
        ttk.Entry(form, textvariable=self.engine_folder, width=62).grid(row=0, column=1, sticky="ew")
        ttk.Button(form, text="Browse", command=self.choose_engine_folder).grid(row=0, column=2, padx=8)
        ttk.Label(form, text="Engine installation profile").grid(row=1, column=0, sticky="w", pady=8)
        profile = ttk.Combobox(form, textvariable=self.accelerator, values=("cu128", "cu126", "cpu"), state="readonly")
        profile.grid(row=1, column=1, sticky="w")
        Tooltip(profile, "cu128: recent NVIDIA GPUs. cu126: older NVIDIA GPUs. cpu: no NVIDIA acceleration; generation can be very slow. Existing compatible ComfyUI installations may use other GPUs.")
        row = ttk.Frame(self.setup)
        row.pack(fill="x", pady=12)
        self.button(row, "Install AI engine", lambda: self.engine_task("install"), "Downloads ComfyUI and its Python/GPU dependencies into the selected folder. Existing unmarked folders are preserved.")
        self.button(row, "Start / connect engine", lambda: self.engine_task("start"))
        self.button(row, "Stop managed engine", self.stop_engine)
        models = ttk.Frame(self.setup)
        models.pack(fill="x", pady=8)
        self.button(models, "Download SDXL image model", lambda: self.engine_task("images"), "About 7 GB. SDXL Base from Stability AI. Review its license on Hugging Face before downloading.")
        self.button(models, "Download Wan video models", lambda: self.engine_task("video"), "About 18 GB in total. Wan 2.2 5B and its text encoder/VAE. Review the model licenses before downloading.")
        self.button(models, "Import SDXL model", self.import_model, "Copies an existing .safetensors checkpoint; the original stays where it is.")
        row = ttk.Frame(self.setup)
        row.pack(fill="x", pady=16)
        self.button(row, "Configure workers", self.edit_workers)
        self.button(row, "Cancel setup operation", self.setup_cancel.set)
        self.setup_status = tk.StringVar(value="Ready. Downloads and generation never start automatically.")
        ttk.Label(self.setup, textvariable=self.setup_status, wraplength=880).pack(anchor="w", pady=8)
        self.setup_bar = ttk.Progressbar(self.setup)
        self.setup_bar.pack(fill="x", pady=8)
        ttk.Label(self.setup, text="Image generation, AI video generation and hardware encoding share the GPU in turns. CPU conversion workers can run alongside them. Engine setup does not generate media.", wraplength=880).pack(anchor="w", pady=16)

    def choose_engine_folder(self):
        path = filedialog.askdirectory(parent=self, title="Choose AI engine folder")
        if path:
            self.engine_folder.set(path)

    def background_setup(self, operation):
        if self.setup_busy or self.pairing_busy or any(item[0].is_alive() for item in self.controller.running.values()):
            raise ValueError("Stop workers and wait for other setup or pairing operations to finish first.")
        self.setup_busy = True
        self.controller.pairing = True
        self.setup_cancel.clear()
        self.setup_status.set("Working…")
        def run():
            try:
                value = operation()
                self.events.put(("setup_done", value))
            except Exception as error:
                self.events.put(("setup_error", str(error)))
        threading.Thread(target=run, daemon=True, name="vaultcast-setup").start()

    def setup_progress(self, text, done, total):
        self.events.put(("setup_progress", text, done, total))

    def engine_task(self, action):
        from comfy_backend import Engine
        import manager_setup
        config = copy.deepcopy(self.controller.config)
        config.update(comfy_root=self.engine_folder.get().strip(), image_backend="comfyui", comfy_url="http://127.0.0.1:8188")
        if config.get("comfy_root") != self.controller.config.get("comfy_root") or self.controller.config.get("image_backend") != "comfyui":
            config.setdefault("capabilities", {}).update(images={}, video={"verified": False})
            config["models"] = []
        accelerator = self.accelerator.get()
        def operation():
            if not self.engine or self.engine.root != Path(config["comfy_root"]).resolve():
                if self.engine and self.engine.process and self.engine.process.poll() is None:
                    raise ValueError("Stop the managed engine before changing its folder.")
                self.engine = Engine(config)
            if action == "install":
                if self.engine.process and self.engine.process.poll() is None:
                    raise ValueError("Stop the managed AI engine before reinstalling its files.")
                self.engine.install(self.controller.log, self.setup_progress, self.setup_cancel, accelerator)
            elif action == "start":
                self.engine.start(self.controller.log, self.setup_cancel)
            else:
                self.engine.install_pack(action, self.setup_progress, self.setup_cancel)
            manager_setup.save(config)
            return config
        self.background_setup(operation)

    def stop_engine(self):
        if self.engine:
            self.background_setup(lambda: (self.engine.stop(), None)[1])

    def import_model(self):
        source = filedialog.askopenfilename(parent=self, title="Import SDXL checkpoint", filetypes=[("SafeTensor models", "*.safetensors")])
        if not source:
            return
        target = Path(self.engine_folder.get()) / "models/checkpoints" / Path(source).name
        def operation():
            import shutil
            if target.exists():
                raise ValueError("A model with this filename is already installed.")
            target.parent.mkdir(parents=True, exist_ok=True)
            temporary = target.with_suffix(".importing")
            try:
                with open(source, "rb") as incoming, temporary.open("wb") as output:
                    while True:
                        if self.setup_cancel.is_set():
                            raise InterruptedError("Import canceled.")
                        block = incoming.read(4 * 1024 * 1024)
                        if not block:
                            break
                        output.write(block)
                temporary.replace(target)
            finally:
                temporary.unlink(missing_ok=True)
        self.background_setup(operation)

    def edit_workers(self):
        if self.setup_busy or self.pairing_busy or any(item[0].is_alive() for item in self.controller.running.values()):
            raise ValueError("Stop workers before reconfiguring them.")
        dialog = tk.Toplevel(self)
        dialog.title("Configure workers")
        dialog.transient(self)
        dialog.grab_set()
        rows = []
        ttk.Label(dialog, text="Choose each worker's role. CPU cores are numbered from 0; use commas, for example 0,1,2,3.", wraplength=800, padding=12).pack()
        table = ttk.Frame(dialog, padding=12)
        table.pack(fill="both", expand=True)
        for column, title in enumerate(("Worker", "Role", "CPU cores", "GPU number")):
            ttk.Label(table, text=title).grid(row=0, column=column, padx=8)
        roles = {"AI images": "images", "AI video generation": "video", "CPU conversion": "libx264", "NVIDIA conversion": "h264_nvenc", "Intel conversion": "h264_qsv", "AMD conversion": "h264_amf"}
        def add(slot=None):
            slot = slot or {}
            role = "AI images" if slot.get("images") else "AI video generation" if slot.get("video") else next((k for k,v in roles.items() if v == slot.get("encoding_encoder", "libx264")), "CPU conversion")
            variables = (tk.StringVar(value=role), tk.StringVar(value=",".join(map(str, slot.get("cpu_cores", [])))), tk.StringVar(value=str(slot.get("encoding_device", 0))))
            index = len(rows) + 1
            ttk.Label(table, text=str(index)).grid(row=index, column=0)
            ttk.Combobox(table, textvariable=variables[0], values=list(roles) + ["Disabled"], state="readonly", width=24).grid(row=index, column=1, padx=8, pady=4)
            ttk.Entry(table, textvariable=variables[1], width=30).grid(row=index, column=2, padx=8)
            ttk.Spinbox(table, textvariable=variables[2], from_=0, to=15, width=6).grid(row=index, column=3, padx=8)
            rows.append((slot.get("id", max([item[0] for item in rows], default=0) + 1), variables))
        for slot in self.controller.config.get("slots", []):
            add(slot)
        if not rows:
            add({"cpu_cores": worker.available_cpu_cores()})
        buttons = ttk.Frame(dialog, padding=12)
        buttons.pack(fill="x")
        self.button(buttons, "Add worker", add)
        def apply():
            slots = []
            for slot_id, (role, cores, gpu) in rows:
                if role.get() == "Disabled":
                    continue
                kind = roles[role.get()]
                cpu = [int(c.strip()) for c in cores.get().split(",") if c.strip()]
                if len(cpu) != len(set(cpu)):
                    raise ValueError("CPU core numbers must not repeat within a worker.")
                slots.append({"id": slot_id, "images": kind == "images", "video": kind == "video", "encoding": kind not in ("images", "video"), "cpu_cores": cpu if kind not in ("images", "video") else [], "encoding_encoder": kind if kind != "images" else "libx264", "encoding_device": int(gpu.get()), "encoding_mode": "cpu" if kind == "libx264" else "hardware"})
            if not slots or len(slots) > 32:
                raise ValueError("Configure between 1 and 32 workers.")
            cfg = copy.deepcopy(self.controller.config)
            cfg["comfy_root"] = self.engine_folder.get().strip()
            devices = {s["encoding_device"] for s in slots if s.get("images") or s.get("video")}
            if len(devices) > 1:
                raise ValueError("AI workers share one engine. Choose the same GPU number for all image/video workers.")
            if devices:
                cfg["ai_gpu_device"] = next(iter(devices))
            def operation():
                import manager_setup
                if devices and cfg.get("ai_gpu_device", 0) != self.controller.config.get("ai_gpu_device", 0):
                    if not self.engine or not self.engine.process or self.engine.process.poll() is not None:
                        raise ValueError("Start the managed engine before changing its GPU. An externally managed engine must be configured outside this manager.")
                    self.engine.stop()
                    self.engine.config = cfg
                    self.engine.start(self.controller.log, self.setup_cancel)
                return manager_setup.prepare(cfg, slots, self.controller.log, self.setup_progress, self.setup_cancel)
            dialog.destroy()
            self.background_setup(operation)
        self.button(buttons, "Check & save workers", apply, "Checks installed models and runs a short FFmpeg encoder check. No AI image or video is generated.")

    def begin_pair(self, with_code):
        if self.setup_busy:
            raise ValueError("Wait for setup to finish before pairing.")
        if self.pairing_busy:
            return
        if any(item[0].is_alive() for item in self.controller.running.values()):
            raise ValueError("Stop all workers before pairing.")
        server = pairing.server_address(self.server.get())
        if not with_code and server.startswith("http://"):
            if not messagebox.askyesno("Unencrypted connection", "This address uses HTTP. Your Vaultcast password will travel without encryption. Continue on this trusted network?", parent=self):
                return
        config = copy.deepcopy(self.controller.config)
        username, password, code = self.username.get(), self.password.get(), self.pair_code.get() if with_code else ""
        if with_code:
            import re
            if not re.fullmatch(r"[a-fA-F0-9]{32}", "".join(code.split())):
                raise ValueError("Enter the 32-character one-time pairing code from Vaultcast.")
            username, password = "", ""
        self.password.set("")
        self.pair_code.set("")
        self.pairing_busy = True
        self.controller.pairing = True
        self.login_button.configure(state="disabled")
        self.code_button.configure(state="disabled")
        self.pair_status.set("Pairing with Vaultcast…")
        def connect():
            try:
                result = pairing.pair(config, server, username, password, code)
                config.update(server=server, worker_token=result["worker_token"], worker_id=result["worker_id"])
                Path(worker.CONFIG_DIR).mkdir(parents=True, exist_ok=True)
                worker.save_config(config)
                self.events.put(("paired", config))
            except Exception as error:
                self.events.put(("pair_error", str(error)))
        threading.Thread(target=connect, daemon=True, name="vaultcast-pairing").start()

    def poll(self):
        for _ in range(200):
            try:
                event = self.events.get_nowait()
            except queue.Empty:
                break
            if event[0] == "setup_progress":
                self.setup_status.set(event[1])
                self.setup_bar.configure(value=100 * event[2] / event[3] if event[3] else 0)
            elif event[0] in ("setup_done", "setup_error"):
                self.setup_busy = False
                self.controller.pairing = False
                if event[0] == "setup_done":
                    if event[1] is not None:
                        self.controller.config = event[1]
                    self.setup_status.set("Setup operation complete.")
                    for child in self.workers.winfo_children():
                        child.destroy()
                    self.cards.clear()
                    self.build_workers()
                else:
                    self.setup_status.set("Setup stopped: " + event[1])
            elif event[0] in ("paired", "pair_error"):
                self.pairing_busy = False
                self.controller.pairing = False
                self.login_button.configure(state="normal")
                self.code_button.configure(state="normal")
                if event[0] == "paired":
                    self.controller.config = event[1]
                    self.pair_status.set("Paired successfully. Your password and sign-in session were not saved.")
                else:
                    self.pair_status.set("Could not pair: " + event[1])
            elif event[0] in ("status", "finished", "progress") and event[1] in self.cards:
                status, bar = self.cards[event[1]]
                if event[0] == "status":
                    status.set(event[2])
                elif event[0] == "finished":
                    bar.stop()
                    status.set("Stopped · details in Activity or worker.log")
                else:
                    value = event[2].get("phase_progress")
                    bar.stop()
                    if isinstance(value, (int, float)):
                        bar.configure(mode="determinate", value=max(0, min(100, value)))
                    else:
                        bar.configure(mode="indeterminate")
                        bar.start(40)
            elif event[0] == "log":
                self.logs.configure(state="normal")
                self.logs.insert("end", event[1] + "\n")
                if int(self.logs.index("end-1c").split(".")[0]) > 1500:
                    self.logs.delete("1.0", "200.0")
                self.logs.see("end")
                self.logs.configure(state="disabled")
        if self.closing and not any(item[0].is_alive() for item in self.controller.running.values()):
            self.controller.shutdown.set()
            self.destroy()
            return
        self.after(150, self.poll)

    def close(self):
        if self.setup_busy:
            self.setup_cancel.set()
            messagebox.showinfo("Stopping setup", "Setup is stopping. Close the manager once it has finished; downloads are retained for resuming.", parent=self)
            return
        if self.pairing_busy:
            messagebox.showinfo("Pairing in progress", "Wait for pairing to finish before closing.", parent=self)
            return
        if any(item[0].is_alive() for item in self.controller.running.values()):
            if not messagebox.askyesno("Close Vaultcast workers?", "Stop workers and close when their current operations have stopped? Conversion checkpoints will be kept.", parent=self):
                return
            self.closing = True
            for slot_id in self.cards:
                self.controller.stop(slot_id, False)
        else:
            self.controller.shutdown.set()
            self.destroy()


def main():
    try:
        import truststore
        truststore.inject_into_ssl()
    except ImportError:
        pass
    path = Path.home() / ".vaultcast" / "worker.log"
    path.parent.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(filename=path, encoding="utf-8", level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    # pythonw has no standard streams. Existing worker progress helpers still use them.
    if sys.stdout is None:
        sys.stdout = open(path.with_name("worker-console.log"), "a", encoding="utf-8", buffering=1)
    if sys.stderr is None:
        sys.stderr = sys.stdout
    Manager().mainloop()


if __name__ == "__main__":
    main()
