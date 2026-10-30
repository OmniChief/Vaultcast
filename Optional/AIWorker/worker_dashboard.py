"""Compact worker table with vector controls, independent of system icon fonts."""
import tkinter as tk
from tkinter import ttk
from tkinter import font as tkfont


def progress_label(data):
    """Format actual metrics, never terminal-rendered text."""
    phase = data.get("phase")
    percent = data.get("phase_progress")
    if data.get("stage") == "waiting for GPU":
        return "Waiting for GPU"
    if phase in ("upload", "download"):
        rate = max(0, float(data.get("bytes_per_second") or 0)) / 1000000
        label = "Upload" if phase == "upload" else "Download"
        suffix = " - %.0f%%" % max(0, min(100, percent)) if isinstance(percent, (int, float)) else ""
        return "%s: %.1f MB/s%s" % (label, rate, suffix)
    if phase in ("wait_upload", "wait_download"):
        return "Waiting to upload" if phase == "wait_upload" else "Waiting to download"
    if phase == "verify":
        return "Finalising"
    if phase == "done":
        return "Completed"
    label = "Encoding" if phase == "encode" else "Generating"
    return "%s: %.0f%%" % (label, max(0, min(100, percent))) if isinstance(percent, (int, float)) else label


def status_label(message):
    text = message.lower()
    for fragment, label in (("stopped", "Stopped"), ("connection", "Reconnecting"),
                            ("reconnect", "Reconnecting"), ("unavailable", "Reconnecting"),
                            ("failed", "Needs attention"), ("error", "Needs attention"),
                            ("paused:", "Needs attention"), ("finishing", "Finishing current job"),
                            ("stopping", "Stopping"), ("retry", "Retrying")):
        if fragment in text:
            return label
    if "waiting" in text:
        return "Waiting for GPU" if "gpu" in text else "Waiting for job"
    if "generat" in text:
        return "Generating"
    return "Completed" if "delivered" in text else "Working"


class IconButton(tk.Canvas):
    def __init__(self, parent, icon, action, tooltip, Tooltip):
        super().__init__(parent, width=27, height=27, background="white", highlightthickness=1,
                         highlightbackground="white", takefocus=True, cursor="hand2")
        self.icon, self.action, self.enabled = icon, action, True
        self.draw()
        self.bind("<Button-1>", lambda _e: self.invoke())
        self.bind("<Return>", lambda _e: self.invoke())
        self.bind("<space>", lambda _e: self.invoke())
        self.bind("<Enter>", lambda _e: self.configure(background="#edf3ff"))
        self.bind("<Leave>", lambda _e: self.configure(background="white"))
        self.bind("<FocusIn>", lambda _e: self.configure(highlightbackground="#2563eb"))
        self.bind("<FocusOut>", lambda _e: self.configure(highlightbackground="white"))
        Tooltip(self, tooltip)

    def invoke(self):
        if self.enabled:
            self.action()

    def enable(self, value):
        self.enabled = value
        self.configure(cursor="hand2" if value else "arrow")
        self.draw()

    def draw(self):
        self.delete("all")
        color = "#b42332" if self.icon == "delete" else "#365277"
        if not self.enabled:
            color = "#c7cdd6"
        if self.icon == "start":
            self.create_polygon(10,7,10,21,21,14,fill=color)
        elif self.icon == "stop":
            self.create_rectangle(8,8,20,20,fill=color,outline=color)
        elif self.icon == "finish":
            self.create_line(10,8,10,20,fill=color,width=3)
            self.create_line(18,8,18,20,fill=color,width=3)
        elif self.icon == "edit":
            self.create_polygon(8,17,17,8,21,12,12,21,7,22,fill="",outline=color,width=1.5)
            self.create_line(15,10,19,14,fill=color,width=1.5)
        elif self.icon == "delete":
            self.create_line(8,9,20,9,fill=color,width=1.5)
            self.create_rectangle(10,10,18,22,outline=color,width=1.5)
            self.create_line(12,6,16,6,fill=color,width=2)
            self.create_line(13,12,13,19,fill=color)
            self.create_line(16,12,16,19,fill=color)


class Dashboard:
    def __init__(self, app, Tooltip):
        self.app, self.controls = app, {}
        self.file_labels = {}
        self.host = ttk.Frame(app.workers)
        self.host.pack(fill="both", expand=True)
        header = ttk.Frame(self.host)
        header.pack(fill="x", pady=(0, 12))
        self.summary = tk.StringVar()
        ttk.Label(header, textvariable=self.summary, font=("Segoe UI", 11, "bold")).pack(side="left")
        ttk.Button(header, text="+ Add worker", style="Primary.TButton", command=lambda: app.action(lambda: app.edit_workers(add_new=True))).pack(side="right")
        self.stop_all = ttk.Button(header, text="Finish all", command=lambda: [app.controller.stop(i) for i in app.cards])
        self.stop_all.pack(side="right", padx=6)
        self.start_all = ttk.Button(header, text="Start all", command=lambda: app.action(lambda: [app.controller.start(s) for s in app.controller.config.get("slots", []) if s["id"] not in app.pending_removals]))
        self.start_all.pack(side="right", padx=6)
        self.table = ttk.Frame(self.host)
        self.table.pack(fill="x")
        self.tiles = []
        self.columns = 0
        slots = app.controller.config.get("slots", [])
        if not slots:
            ttk.Label(self.table,text="No workers yet. Use + Add worker to get started.",padding=20).pack(fill="x")
        for slot in slots:
            number = slot["id"]
            tile=ttk.Frame(self.table,style="Card.TFrame",padding=(9,6))
            self.tiles.append(tile)
            top=ttk.Frame(tile,style="Card.TFrame")
            top.pack(fill="x")
            ttk.Label(top,text="#%02d" % number,style="CardTitle.TLabel").pack(side="left")
            role = "AI images" if slot.get("images") else "AI video" if slot.get("video") else "CPU encode" if slot.get("encoding_encoder","libx264")=="libx264" else "GPU encode"
            ttk.Label(top,text=role,style="Card.TLabel").pack(side="right")
            resources = "GPU %s" % slot.get("encoding_device",0) if slot.get("images") or slot.get("video") else "%d CPU cores" % len(slot.get("cpu_cores",[]))
            if role=="GPU encode":
                resources += " / " + {"h264_nvenc":"NVIDIA","h264_qsv":"Intel","h264_amf":"AMD"}.get(slot.get("encoding_encoder"),"GPU")
            label=ttk.Label(tile,text=resources,style="Muted.Card.TLabel")
            label.pack(anchor="w",pady=(2,0))
            Tooltip(label,"CPU cores: "+(", ".join(map(str,slot.get("cpu_cores",[]))) or "managed by AI engine"))
            file_text=tk.StringVar(value="")
            file_label=ttk.Label(tile,textvariable=file_text,style="Muted.Card.TLabel",width=21,anchor="w")
            file_label.pack(fill="x",pady=(2,0))
            full_text=tk.StringVar(value="")
            Tooltip(file_label,lambda value=full_text:value.get())
            self.file_labels[number] = (file_text, full_text, file_label, "", None)
            file_label.bind("<Configure>",lambda _event,i=number:self.draw_file(i))
            status=tk.StringVar(value="Stopped")
            label=ttk.Label(tile,textvariable=status,style="Muted.Card.TLabel",width=21,anchor="w")
            label.pack(fill="x",pady=(2,0))
            Tooltip(label,lambda value=status:value.get())
            file_label.status_widget = label
            file_label.pack_forget()
            bar=ttk.Progressbar(tile,maximum=100,length=100)
            actions=ttk.Frame(tile,style="Card.TFrame")
            actions.pack(fill="x",pady=(3,0))
            buttons={}
            for key, text, fn in (
                ("start","Start worker",lambda s=slot: app.controller.start(s)),
                ("finish","Finish current job, then stop",lambda i=number: app.controller.stop(i)),
                ("stop","Stop now; keep conversion checkpoints",lambda i=number: app.controller.stop(i,False)),
                ("edit","Edit worker",lambda i=number: app.edit_workers(selected=i)),
                ("delete","Delete worker",lambda i=number: app.delete_worker(i))):
                buttons[key]=IconButton(actions,key,lambda f=fn: app.action(f),text,Tooltip)
            app.cards[number]=(status,bar)
            self.controls[number]=(buttons,bar,None)
        self.table.bind("<Configure>",lambda event:self.reflow(event.width))
        self.reflow(940)
        self.update()

    def set_file(self, number, data):
        if number not in self.file_labels:
            return
        output = data.get("phase") in ("upload", "wait_upload", "verify", "done")
        name = (data.get("output_name") if output else data.get("source_name")) or data.get("filename") or data.get("title")
        size = data.get("output_bytes") if output else data.get("source_bytes")
        if size is None:
            size = data.get("bytes_total")
        if not name:
            return
        text, full, label, _, _ = self.file_labels[number]
        self.file_labels[number] = (text, full, label, str(name), size)
        self.draw_file(number)

    def draw_file(self, number):
        text, full, label, name, size = self.file_labels[number]
        if not name:
            text.set("")
            label.pack_forget()
            return
        if not label.winfo_manager():
            label.pack(fill="x",pady=(2,0),before=label.status_widget)
        suffix = ""
        if isinstance(size, (int, float)) and size >= 0:
            amount, unit = size, "B"
            for unit in ("B", "KB", "MB", "GB", "TB"):
                if amount < 1000 or unit == "TB":
                    break
                amount /= 1000
            suffix = " | %.1f %s" % (amount, unit)
        full.set(name + suffix)
        font = tkfont.Font(font=("Segoe UI", 9))
        available = max(40, label.winfo_width()-4)
        short = name
        while short and font.measure(short + ("..." if short != name else "") + suffix) > available:
            short = short[:-1]
        text.set(short + ("..." if short != name else "") + suffix)

    def clear_file(self, number):
        if number in self.file_labels:
            text, full, label, _, _ = self.file_labels[number]
            self.file_labels[number] = (text, full, label, "", None)
            full.set("")
            self.draw_file(number)

    def reflow(self, width):
        columns=max(1,width//186)
        if columns==self.columns:
            return
        for col in range(max(columns,self.columns)):
            self.table.columnconfigure(col,weight=1 if col<columns else 0,uniform="tiles" if col<columns else "",minsize=0)
        self.columns=columns
        for i,tile in enumerate(self.tiles):
            tile.grid(row=i//columns,column=i%columns,sticky="nsew",padx=(0,6),pady=(0,6))

    def update(self):
        if not self.host.winfo_exists():
            return
        app=self.app
        active=0
        for number,(buttons,bar,previous) in list(self.controls.items()):
            item=app.controller.running.get(number)
            running=bool(item and item[0].is_alive())
            active+=int(running)
            busy=app.setup_busy or app.pairing_busy
            removing=number in app.pending_removals
            draining=bool(item and item[2].is_set())
            stopping=bool(item and item[1].is_set())
            state=(running,busy,removing,draining,stopping)
            if state==previous:
                continue
            for button in buttons.values():
                button.pack_forget()
            for key in (("finish","stop","edit","delete") if running else ("start","edit","delete")):
                buttons[key].pack(side="left",padx=2)
                buttons[key].enable(not busy and not removing and not (key=="edit" and running) and not (key=="finish" and draining) and not (key=="stop" and stopping))
            if running:
                bar.pack(fill="x",pady=(2,0),before=bar.master.winfo_children()[-1])
            else:
                bar.stop()
                bar.pack_forget()
            self.controls[number]=(buttons,bar,state)
        self.summary.set("%d workers / %d running" % (len(app.cards),active))
        if active:
            self.stop_all.pack(side="right",padx=6,before=self.start_all)
        else:
            self.stop_all.pack_forget()
        self.start_all.configure(state="disabled" if active==len(app.cards) or app.setup_busy or app.pairing_busy else "normal")
