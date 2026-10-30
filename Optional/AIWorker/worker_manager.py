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
        ttk.Label(self.window, text=self.text() if callable(self.text) else self.text, wraplength=340, padding=10).pack()
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
        self.last_status = {}
        if Path(worker.CONFIG_PATH).exists():
            self.config = json.loads(Path(worker.CONFIG_PATH).read_text(encoding="utf-8"))
        threading.Thread(target=self.heartbeat_loop, daemon=True, name="manager-heartbeat").start()
    def set_status(self, slot, text):
        from worker_dashboard import status_label
        text = str(text)
        if self.last_status.get(slot) != text:
            self.log("Worker %s: %s" % (slot, text))
            self.last_status[slot] = text
        self.events.put(("status", slot, status_label(text)))
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
        self.geometry("1040x740")
        self.minsize(760, 520)
        self.events = queue.Queue()
        self.controller = Controller(self.events)
        self.cards = {}
        self.pending_removals = set()
        self.cache_busy = False
        self.closing = False
        self.pairing_busy = False
        self.setup_busy = False
        self.setup_cancel = threading.Event()
        self.engine = None
        style = ttk.Style(self)
        style.theme_use("clam")
        style.configure("TFrame", background="#f3f5f8")
        style.configure("TLabel", background="#f3f5f8", foreground="#18243a", font=("Segoe UI", 10))
        self.configure(background="#f3f5f8")
        style.configure("Card.TFrame", background="#ffffff")
        style.configure("Card.TLabel", background="#ffffff", foreground="#18243a", font=("Segoe UI", 10))
        style.configure("CardTitle.TLabel", background="#ffffff", foreground="#18243a", font=("Segoe UI", 10, "bold"))
        style.configure("Muted.Card.TLabel", background="#ffffff", foreground="#64748b", font=("Segoe UI", 9))
        style.configure("Primary.TButton", background="#2559d6", foreground="#ffffff", borderwidth=0)
        style.map("Primary.TButton", background=[("active", "#1948bc")], foreground=[("active", "#ffffff")])
        style.configure("Danger.TButton", foreground="#b42332")
        style.configure("TNotebook", background="#f3f5f8", borderwidth=0)
        style.configure("TNotebook.Tab", padding=(18, 10), font=("Segoe UI", 10))
        style.configure("Horizontal.TProgressbar", background="#2559d6", troughcolor="#e9eef6", borderwidth=0, thickness=6)
        style.configure("Title.TLabel", font=("Segoe UI", 16, "bold"))
        style.configure("TButton", padding=(9, 5), font=("Segoe UI", 9))
        style.configure("Nav.TButton", padding=(14, 7), background="#f3f5f8", foreground="#64748b", borderwidth=0, font=("Segoe UI", 10))
        style.map("Nav.TButton", background=[("pressed", "#e4ecfa"), ("active", "#eaf0f9")])
        style.configure("Selected.Nav.TButton", background="#e4ecfa", foreground="#1d4ed8")
        style.layout("Compact.TNotebook.Tab", [])
        style.configure("Compact.TNotebook", borderwidth=0, background="#f3f5f8")
        header = ttk.Frame(self, padding=(20, 12))
        header.pack(fill="x")
        ttk.Label(header, text="Vaultcast workers", style="Title.TLabel").pack(side="left")
        ttk.Label(header, text="Version " + VERSION).pack(side="right")
        nav = ttk.Frame(self, padding=(20, 0, 20, 6))
        nav.pack(fill="x")
        self.nav_buttons = []
        self.tabs = ttk.Notebook(self, style="Compact.TNotebook")
        self.tabs.pack(fill="both", expand=True, padx=0, pady=(0, 8))
        worker_page, settings_page, setup_page = [ScrollPage(self.tabs) for _ in range(3)]
        self.workers = worker_page.body
        self.settings = settings_page.body
        self.activity = ttk.Frame(self.tabs, padding=20)
        self.setup = setup_page.body
        update_page = ScrollPage(self.tabs)
        for tab, title in ((worker_page, "Workers"), (setup_page, "Setup & models"), (settings_page, "Connection & settings"), (self.activity, "Activity"), (update_page, "Updates")):
            self.tabs.add(tab, text=title)
            index = len(self.nav_buttons)
            nav_button = ttk.Button(nav, text=title, style="Nav.TButton", command=lambda i=index: self.select_page(i))
            nav_button.pack(side="left", padx=(0, 4))
            self.nav_buttons.append(nav_button)
        self.select_page(0)
        self.build_workers()
        self.build_settings()
        self.build_setup()
        from worker_updates_ui import Updates
        self.updates = Updates(self, update_page.body)
        self.logs = tk.Text(self.activity, wrap="word", state="disabled", font=("Consolas", 10))
        scrollbar = ttk.Scrollbar(self.activity, command=self.logs.yview)
        self.logs.configure(yscrollcommand=scrollbar.set)
        scrollbar.pack(side="right", fill="y")
        self.logs.pack(fill="both", expand=True)
        self.protocol("WM_DELETE_WINDOW", self.close)
        self.after(150, self.poll)
    def select_page(self, index):
        self.tabs.select(index)
        for i, button in enumerate(self.nav_buttons):
            button.configure(style="Selected.Nav.TButton" if i == index else "Nav.TButton")
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
    def rebuild_workers(self):
        previous = {key: (status.get(), float(bar["value"])) for key, (status, bar) in self.cards.items()}
        for child in self.workers.winfo_children():
            child.destroy()
        self.cards.clear()
        self.build_workers()
        for key, (text, value) in previous.items():
            if key in self.cards:
                self.cards[key][0].set(text)
                self.cards[key][1].configure(value=value)
    def build_workers(self):
        from worker_dashboard import Dashboard
        self.dashboard = Dashboard(self, Tooltip)
    def delete_worker(self, slot_id, confirmed=False):
        if self.setup_busy or self.pairing_busy:
            raise ValueError("Wait for setup or pairing to finish before removing a worker.")
        item = self.controller.running.get(slot_id)
        if item and item[0].is_alive():
            if slot_id in self.pending_removals:
                return
            choice = messagebox.askyesnocancel("Remove running worker?", "Finish this worker's current job before removing it?\n\nYes: finish the job, then remove.\nNo: stop now, then remove.\nConversion checkpoints and models are kept.", parent=self)
            if choice is None:
                return
            self.pending_removals.add(slot_id)
            self.controller.stop(slot_id, finish=choice)
            self.rebuild_workers()
            return
        if not confirmed and not messagebox.askyesno("Delete worker?", "Remove Worker %s from this computer? Models, pairing and conversion files will be kept." % slot_id, parent=self):
            return
        with self.controller.lock:
            config = copy.deepcopy(self.controller.config)
            config["slots"] = [s for s in config.get("slots", []) if s["id"] != slot_id]
            config["contributions"] = {k: any(s.get(k) for s in config["slots"]) for k in ("images", "encoding", "video")}
            worker.save_config(config)
            self.controller.config = config
            self.controller.running.pop(slot_id, None)
        self.pending_removals.discard(slot_id)
        self.rebuild_workers()
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
            ttk.Entry(self.settings, textvariable=variable, show="\xe2\u20ac\xa2" if hidden else "", width=56).pack(anchor="w", pady=(3, 8))
        buttons = ttk.Frame(self.settings)
        buttons.pack(fill="x", pady=8)
        self.login_button = self.button(buttons, "Sign in & pair", lambda: self.begin_pair(False))
        self.code_button = self.button(buttons, "Pair with code", lambda: self.begin_pair(True))
        self.pair_status = tk.StringVar(value="Paired" if self.controller.config.get("worker_token") else "Not paired")
        ttk.Label(self.settings, textvariable=self.pair_status, wraplength=850).pack(anchor="w")
        ttk.Label(self.settings, text="Your existing pairing and conversion checkpoints are preserved. This manager does not remove InvokeAI installations or models.", wraplength=850).pack(anchor="w", pady=24)
        ttk.Separator(self.settings).pack(fill="x", pady=16)
        ttk.Label(self.settings, text="Worker cache", font=("Segoe UI", 13, "bold")).pack(anchor="w")
        self.cache_status = tk.StringVar(value="Calculating cache size...")
        ttk.Label(self.settings, textvariable=self.cache_status, wraplength=820).pack(anchor="w", pady=8)
        self.discard_cache = tk.BooleanVar(value=False)
        ttk.Checkbutton(self.settings, text="Also discard retained downloads and unfinished conversions (loses resumable progress)", variable=self.discard_cache).pack(anchor="w")
        row = ttk.Frame(self.settings)
        row.pack(fill="x", pady=8)
        self.button(row, "Refresh size", self.refresh_cache)
        self.button(row, "Clear cache", self.clear_cache)
        ttk.Label(self.settings, text="Models, installed engines and Python environments are not cache and are never cleared. The latest update backup and recent update transactions are kept.", wraplength=820).pack(anchor="w")
        self.after(1500, self.refresh_cache)
    def refresh_cache(self):
        if self.cache_busy:
            return
        self.cache_busy = True
        def scan():
            try:
                from worker_cache import inventory, maintenance
                maintenance()
                self.events.put(("cache_scanned", inventory()))
            except Exception as error:
                self.events.put(("cache_error", str(error)))
        threading.Thread(target=scan, daemon=True, name="cache-scan").start()
    def clear_cache(self):
        if self.cache_busy or self.setup_busy or self.pairing_busy:
            raise ValueError("Wait for other operations to finish first.")
        if any(item[0].is_alive() for item in self.controller.running.values()):
            raise ValueError("Stop workers before clearing cache. Close older worker versions running elsewhere on this computer too.")
        discard = self.discard_cache.get()
        prompt = "Clear downloaded installers and confirmed-job cache? Settings, models and Python environments are kept."
        if discard:
            prompt += "\n\nRetained downloads, AI outputs and unfinished conversion files will also be deleted. Those jobs will need to restart or regenerate."
        if not messagebox.askyesno("Clear worker cache?", prompt, parent=self):
            return
        self.cache_busy = True
        self.setup_busy = True
        self.controller.pairing = True
        def clean():
            try:
                from worker_cache import clear
                self.events.put(("cache_cleared", clear(discard)))
            except Exception as error:
                self.events.put(("cache_clear_error", str(error)))
        threading.Thread(target=clean, daemon=True, name="cache-clear").start()
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
        self.setup_status.set("Working\xe2\u20ac\xa6")
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
    def edit_workers(self, selected=None, add_new=False):
        if self.setup_busy or self.pairing_busy or any(item[0].is_alive() for item in self.controller.running.values()):
            raise ValueError("Stop workers before reconfiguring them.")
        dialog = tk.Toplevel(self)
        dialog.title("Add worker" if add_new else "Edit worker" if selected is not None else "Configure workers")
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
        existing = self.controller.config.get("slots", [])
        if add_new:
            ids = {slot["id"] for slot in existing}
            number = next((i for i in range(1, 33) if i not in ids), None)
            if number is None:
                dialog.destroy()
                raise ValueError("This computer already has 32 workers.")
            used = {c for slot in existing for c in slot.get("cpu_cores", [])}
            add({"id": number, "cpu_cores": [c for c in worker.available_cpu_cores() if c not in used]})
        else:
            for slot in existing:
                if selected is None or slot["id"] == selected:
                    add(slot)
        if not rows:
            add({"cpu_cores": worker.available_cpu_cores()})
        buttons = ttk.Frame(dialog, padding=12)
        buttons.pack(fill="x")
        if selected is None and not add_new:
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
            if selected is not None or add_new:
                slots = [s for s in existing if s["id"] != selected] + slots
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
        self.pair_status.set("Pairing with Vaultcast\xe2\u20ac\xa6")
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
        if self.updates.poll():
            return
        self.dashboard.update()
        for slot_id in list(self.pending_removals):
            item = self.controller.running.get(slot_id)
            if not item or not item[0].is_alive():
                self.action(lambda i=slot_id: self.delete_worker(i, confirmed=True))
        for _ in range(200):
            try:
                event = self.events.get_nowait()
            except queue.Empty:
                break
            if event[0] in ("cache_scanned", "cache_error", "cache_cleared", "cache_clear_error"):
                from worker_cache import size_text
                self.cache_busy = False
                if event[0] in ("cache_cleared", "cache_clear_error"):
                    self.setup_busy = False
                    self.controller.pairing = False
                if event[0] == "cache_scanned":
                    data = event[1]
                    self.cache_status.set("Cache: %s total | %s reclaimable | %s retained for resume%s" % (size_text(data["reclaimable"]+data["resumable"]+data["protected"]), size_text(data["reclaimable"]), size_text(data["resumable"]), " | %s update backup/staging protected%s" % (size_text(data["protected"]), " | Some files could not be measured" if data["errors"] else "")))
                    self.after(30000, self.refresh_cache)
                elif event[0] == "cache_cleared":
                    freed, errors = event[1]
                    self.controller.log("Cache cleared:", size_text(freed), "Errors:", "; ".join(errors))
                    self.cache_status.set("Cleared %s. %d cleanup errors; details in Activity." % (size_text(freed), len(errors)))
                    self.after(1500, self.refresh_cache)
                else:
                    self.cache_status.set("Cache operation failed: " + event[1])
            elif event[0].startswith("update_"):
                self.updates.event(event)
            elif event[0] == "setup_progress":
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
                    if event[2] in ("Waiting for job", "Stopped"):
                        self.dashboard.clear_file(event[1])
                elif event[0] == "finished":
                    bar.stop()
                    status.set("Stopped")
                    self.dashboard.clear_file(event[1])
                else:
                    from worker_dashboard import progress_label
                    self.dashboard.set_file(event[1], event[2])
                    status.set(progress_label(event[2]))
                    value = event[2].get("phase_progress")
                    if event[2].get("phase") in ("verify", "wait_upload", "wait_download") or event[2].get("stage") == "waiting for GPU":
                        value = None
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
