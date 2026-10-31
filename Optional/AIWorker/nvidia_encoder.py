"""Select a private driver-compatible NVENC runtime without replacing user tools."""
from pathlib import Path
import re
import shutil
import zipfile

from comfy_backend import ROOT, download, stopped
from encoding_worker import check_encoder

# Publisher's immutable release asset; checksum also recorded by Microsoft's
# winget-pkgs/Gyan.FFmpeg.Essentials/7.1 installer manifest.
COMPAT_URL = "https://github.com/GyanD/codexffmpeg/releases/download/7.1/ffmpeg-7.1-essentials_build.zip"
COMPAT_SHA256 = "fa7d4d7e795db0e2503f49f105f46ed5852386f0cfdd819899be3b65ebde24fc"


def checked_runtime(ffmpeg, ffprobe, device, threads, temporary, log, progress, cancel):
    try:
        check_encoder(ffmpeg, ffprobe, "h264_nvenc", device, threads, temporary)
        return ffmpeg, ffprobe
    except RuntimeError as error:
        detail = str(error)
        log("NVIDIA encoder check details:", detail)
        match = re.search(r"Required:\s*(\d+)\.(\d+)\s+Found:\s*(\d+)\.(\d+)", detail, re.I)
        if "nvenc api version" not in detail.lower() or not match:
            raise RuntimeError("NVIDIA encoding could not start on GPU %s. See Activity for the full encoder error." % device) from error
        supported = tuple(map(int, match.groups()[2:]))
        if supported < (12, 2):
            raise RuntimeError("The NVIDIA driver supports NVENC %d.%d. No compatible bundled runtime is available for this driver. Update the NVIDIA driver or choose CPU conversion. Details are in Activity." % supported) from error
    log("Current FFmpeg needs a newer NVENC API than the driver provides. Installing the private NVENC 12.2-compatible runtime for this NVIDIA worker.")
    selected, probe = compatibility_runtime(progress, cancel)
    try:
        check_encoder(selected, probe, "h264_nvenc", device, threads, temporary)
    except RuntimeError as error:
        log("Compatible NVIDIA runtime check failed:", error)
        raise RuntimeError("NVIDIA encoding could not start on GPU %s, including with the compatible runtime. Check Activity for the driver/device error or choose another GPU." % device) from error
    log("NVIDIA hardware encoding is ready with the driver-compatible runtime.")
    return selected, probe


def compatibility_runtime(progress, cancel):
    """Reuse the verified private build for legacy NVENC or Intel QSV drivers.

    Callers hold GPU admission: setup has exclusive admission, and only one
    Intel conversion can install here at a time. Existing files stay untouched.
    """
    directory = ROOT / "ffmpeg-nvenc-12-2"
    selected = directory / "ffmpeg.exe"
    probe = directory / "ffprobe.exe"
    if not selected.is_file() or not probe.is_file():
        archive = download(COMPAT_URL, ROOT / "downloads/ffmpeg-nvenc-12-2.zip", COMPAT_SHA256, progress, cancel)
        directory.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(archive) as package:
            for name in ("ffmpeg.exe", "ffprobe.exe"):
                stopped(cancel)
                member = next(entry for entry in package.namelist() if entry.endswith("/bin/" + name))
                temporary_file = directory / (name + ".installing")
                with package.open(member) as source, temporary_file.open("wb") as output:
                    shutil.copyfileobj(source, output)
                temporary_file.replace(directory / name)
        archive.unlink(missing_ok=True)
    stopped(cancel)
    return str(selected), str(probe)
