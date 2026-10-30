"""In-app worker updates; network and downloads never block Tk."""
from pathlib import Path
import threading
import tkinter as tk
from tkinter import ttk, messagebox

import worker_updater as updater
from worker_version import VERSION


class Updates:
    def __init__(self, app, page):
        self.app, self.plan, self.pending = app, None, None
        self.checking = False
        self.preferences = updater.read_json(updater.STATE / "preferences.json", {})
        self.channel = tk.StringVar(value=self.preferences.get("channel", "Preview"))
        if self.channel.get() not in updater.CHANNELS:
            self.channel.set("Preview")
        self.automatic = tk.BooleanVar(value=self.preferences.get("automatic", True) is not False)
        self.status = tk.StringVar(value="Check for worker updates here. Installed: " + VERSION)
        result = updater.read_json(updater.STATE / "result.json")
        if result:
            self.status.set("Last update installed " + result.get("version", "") if result.get("ok") else "Last update failed: " + result.get("error", "Unknown error"))
        ttk.Label(page, text="Worker updates", style="Title.TLabel").pack(anchor="w", pady=(0, 12))
        ttk.Label(page, text="Installed version: " + VERSION).pack(anchor="w")
        ttk.Label(page, text="Stable follows main. Preview follows the current worker development branch.", wraplength=700).pack(anchor="w", pady=12)
        self.channel_box = ttk.Combobox(page, textvariable=self.channel, values=list(updater.CHANNELS), state="readonly", width=20)
        self.channel_box.pack(anchor="w")
        self.channel.trace_add("write", self.changed_channel)
        ttk.Checkbutton(page, text="Check for updates when the manager opens", variable=self.automatic, command=self.save_preferences).pack(anchor="w", pady=12)
        row = ttk.Frame(page)
        row.pack(fill="x", pady=8)
        self.check_button = ttk.Button(row, text="Check for updates", command=self.check)
        self.check_button.pack(side="left", padx=(0, 8))
        self.install_button = ttk.Button(row, text="Install update", command=lambda: app.action(self.install), state="disabled")
        self.install_button.pack(side="left")
        self.cancel_button = ttk.Button(row, text="Cancel update", command=app.setup_cancel.set, state="disabled")
        self.cancel_button.pack(side="left", padx=8)
        ttk.Button(row, text="Update logs & backups", command=lambda: app.action(self.open_files)).pack(side="left", padx=8)
        ttk.Label(page, textvariable=self.status, wraplength=760).pack(anchor="w", pady=12)
        self.details = tk.StringVar()
        ttk.Label(page, textvariable=self.details, wraplength=760, foreground="#64748b").pack(anchor="w")
        ttk.Label(page, text="Updates finish running jobs before restarting the manager. Workers stay stopped after restart. Pairing, settings, AI models and conversion checkpoints are kept. Originals are backed up before replacing files.", wraplength=760).pack(anchor="w", pady=20)
        if self.automatic.get():
            app.after(3000, self.check)

    def save_preferences(self):
        updater.write_json(updater.STATE / "preferences.json", {"channel": self.channel.get(), "automatic": self.automatic.get()})

    def open_files(self):
        import os
        updater.STATE.mkdir(parents=True, exist_ok=True)
        os.startfile(str(updater.STATE))

    def changed_channel(self, *_args):
        self.plan = None
        self.install_button.configure(state="disabled")
        self.save_preferences()

    def check(self):
        if self.checking or self.app.setup_busy:
            return
        self.checking = True
        self.plan = None
        channel = self.channel.get()
        self.check_button.configure(state="disabled")
        self.install_button.configure(state="disabled")
        self.status.set("Checking " + channel + "...")
        def run():
            try:
                plan = updater.check(Path(__file__).parent, channel)
                self.app.events.put(("update_checked", plan))
            except Exception as error:
                self.app.events.put(("update_check_error", str(error)))
        threading.Thread(target=run, daemon=True, name="worker-update-check").start()

    def install(self):
        app = self.app
        if not self.plan or not self.plan["available"]:
            return
        if app.setup_busy or app.pairing_busy or app.pending_removals:
            raise ValueError("Finish setup, pairing or worker removal before installing an update.")
        if not messagebox.askyesno("Install worker update?", "Download and verify worker %s, finish current jobs, then restart the manager? Workers stay stopped after restart." % self.plan["version"], parent=app):
            return
        plan = self.plan
        app.setup_busy = True
        app.controller.pairing = True
        app.setup_cancel.clear()
        self.install_button.configure(state="disabled")
        self.check_button.configure(state="disabled")
        self.channel_box.configure(state="disabled")
        self.cancel_button.configure(state="normal")
        self.status.set("Downloading and verifying the update...")
        def run():
            try:
                manifest = updater.stage(plan, lambda text: app.events.put(("update_progress", text)), app.setup_cancel)
                app.events.put(("update_staged", str(manifest)))
            except Exception as error:
                app.events.put(("update_install_error", str(error)))
        threading.Thread(target=run, daemon=True, name="worker-update-download").start()

    def event(self, event):
        kind, value = event
        if kind in ("update_checked", "update_check_error"):
            self.checking = False
            self.check_button.configure(state="normal")
        if kind == "update_checked":
            if value["channel"] != self.channel.get():
                return
            self.plan = value
            self.status.set("Worker %s is available." % value["version"] if value["available"] else "No newer worker update is available on this channel.")
            self.details.set("%d changed files. Source commit: %s" % (len(value["changed"]), value["commit"][:12]))
            self.install_button.configure(state="normal" if value["available"] else "disabled")
        elif kind == "update_check_error":
            self.status.set("Could not check for updates: " + value)
        elif kind == "update_progress":
            self.status.set(value)
        elif kind == "update_staged":
            self.pending = value
            self.status.set("Update verified. Waiting for current jobs to finish...")
            for number in self.app.controller.running:
                self.app.controller.stop(number, finish=True)
        elif kind == "update_install_error":
            self.pending = None
            self.app.setup_busy = False
            self.app.controller.pairing = False
            self.check_button.configure(state="normal")
            self.status.set("Update stopped: " + value)
            self.channel_box.configure(state="readonly")
            self.cancel_button.configure(state="disabled")

    def poll(self):
        if self.pending and self.app.setup_cancel.is_set():
            self.event(("update_install_error", "Canceled; workers may have finished and stopped. Installed files have not changed."))
        elif self.pending and not any(item[0].is_alive() for item in self.app.controller.running.values()):
            try:
                updater.launch(self.pending)
                self.app.controller.shutdown.set()
                self.app.destroy()
                return True
            except Exception as error:
                self.event(("update_install_error", str(error)))
        return False
