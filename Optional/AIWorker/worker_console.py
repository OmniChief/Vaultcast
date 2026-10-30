"""Shared, fixed-height terminal display for all contributor slots."""
from collections import deque
import os
import shutil
import sys
import threading
from worker_version import VERSION


def slot_kind(slot):
    kinds = []
    if slot.get("images"):
        kinds.append("Image generation")
    if slot.get("video"):
        kinds.append("AI video generation")
    if slot.get("encoding"):
        names = {"h264_nvenc":"NVIDIA NVENC", "h264_qsv":"Intel Quick Sync", "h264_amf":"AMD AMF"}
        encoder = slot.get("encoding_encoder", "libx264")
        kinds.append("Hardware encoding / " + names[encoder] if encoder in names else "CPU encoding")
    return " + ".join(kinds)


class WorkerConsole:
    def __init__(self, slots, stream=None):
        self.stream = stream or sys.stdout
        self.rows = {slot["id"]: "Waiting for job" for slot in slots}
        self.kinds = {slot["id"]: slot_kind(slot) for slot in slots}
        self.limits = {"download":1,"upload":1}
        self.messages = deque(maxlen=3)
        self.buffers = {}
        self.lock = threading.RLock()
        self.enabled = bool(self.stream.isatty())
        self.old_mode = None
        self.handle = None
        if self.enabled and os.name == "nt":
            import ctypes
            import msvcrt
            kernel = ctypes.windll.kernel32
            kernel.GetConsoleMode.argtypes = [ctypes.c_void_p, ctypes.POINTER(ctypes.c_ulong)]
            kernel.SetConsoleMode.argtypes = [ctypes.c_void_p, ctypes.c_ulong]
            self.handle = msvcrt.get_osfhandle(self.stream.fileno())
            mode = ctypes.c_ulong()
            self.enabled = bool(kernel.GetConsoleMode(self.handle, ctypes.byref(mode)) and kernel.SetConsoleMode(self.handle, mode.value | 4))
            if self.enabled: self.old_mode = mode.value
        self.previous = None
        self.closed = False

    def __enter__(self):
        if self.enabled:
            self.previous = (sys.stdout, sys.stderr)
            sys.stdout = sys.stderr = self
            self.stream.write("\x1b[?1049h\x1b[?25l\x1b[2J")
            self._draw()
        return self

    def __exit__(self, *args):
        with self.lock:
            self.closed = True
            if self.previous:
                sys.stdout, sys.stderr = self.previous
                self.stream.write("\x1b[?25h\x1b[?1049l")
                self.stream.flush()
            if self.old_mode is not None:
                import ctypes
                ctypes.windll.kernel32.SetConsoleMode(self.handle, self.old_mode)

    def __getattr__(self, name):
        return getattr(self.stream, name)

    def write(self, text):
        written = len(text)
        with self.lock:
            key = threading.get_ident()
            text = self.buffers.get(key, "") + text
            lines = text.split("\n")
            self.buffers[key] = lines.pop()
            for line in lines:
                if line.strip(): self.messages.append(line.strip())
            if lines: self._draw()
        return written

    def flush(self):
        self.stream.flush()

    def set_status(self, slot, text):
        with self.lock:
            if self.closed: return
            if self.rows.get(slot) == text: return
            self.rows[slot] = text
            if self.enabled: self._draw()

    def set_kind(self, slot, text):
        with self.lock:
            self.kinds[slot] = text
            if self.enabled:
                self._draw()

    def set_limits(self, limits):
        if not isinstance(limits, dict): return
        with self.lock:
            if self.closed: return
            values = {key:max(1,min(32,int(limits.get(key,1)))) for key in ("download","upload")}
            if values == self.limits: return
            self.limits = values
            if self.enabled: self._draw()

    def log(self, text):
        with self.lock:
            if self.closed: return
            if self.enabled:
                self.messages.append(text)
                self._draw()
            else:
                print(text, file=self.stream, flush=True)

    def _draw(self):
        if self.closed: return
        columns, height = shutil.get_terminal_size((120, 30))
        width = max(1, columns - 1)
        room = max(1, height - 8)
        rows = sorted(self.rows.items())
        lines = ["Vaultcast Contributor " + VERSION + " | Ctrl+C to stop",
            "Transfer slots per computer: " + str(self.limits['download']) + " download / " + str(self.limits['upload']) + " upload. GPU jobs take turns; CPU encodes run in parallel.", ""]
        lines += ["[Worker " + str(slot) + " | " + self.kinds.get(slot, "") + "] " + text for slot, text in rows[:room]]
        if len(rows) > room: lines.append(str(len(rows)-room) + " more workers (increase window height to show all)")
        lines += [""] + list(self.messages)
        # Never write the bottom screen row: a newline there would scroll the display.
        lines = lines[:max(1, height-1)]
        clean = lambda line: "".join(char if char.isprintable() else " " for char in line)[:width]
        self.stream.write("\x1b[H" + "\n".join("\r\x1b[2K" + clean(line) for line in lines) + "\x1b[J")
        self.stream.flush()
