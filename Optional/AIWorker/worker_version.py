"""Version shared by the desktop manager, console and diagnostics."""
from pathlib import Path


def version():
    for file in (Path(__file__).with_name("VERSION"), Path(__file__).resolve().parents[2] / "Application" / "VERSION"):
        if file.is_file():
            return file.read_text(encoding="utf-8").strip()
    return "unknown"


VERSION = version()
