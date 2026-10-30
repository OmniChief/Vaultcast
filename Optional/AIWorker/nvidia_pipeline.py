"""Keep supported NVIDIA conversion frames on the selected GPU."""


def accelerated_command(command, video, device):
    # Preserve the padding path for odd dimensions and unusual pixel formats.
    # Codec/profile support differs by card; a real decode failure retries the
    # original command with NVENC still enabled, never silently selecting x264.
    if (video.get("codec_name") not in ("h264", "hevc", "vp8", "vp9", "av1",
                                       "mpeg1video", "mpeg2video", "vc1")
            or video.get("pix_fmt") not in ("yuv420p", "yuv420p10le", "nv12", "p010le")
            or not video.get("width") or not video.get("height")
            or int(video["width"]) % 2 or int(video["height"]) % 2):
        return None
    result = list(command)
    at = result.index("-i")
    result[at:at] = ["-hwaccel", "cuda", "-hwaccel_device", str(device),
                     "-hwaccel_output_format", "cuda"]
    result[result.index("-pix_fmt") + 1] = "cuda"
    # Convert 10-bit HEVC to NVENC's 8-bit H.264 format on the GPU. Always
    # allocate filter output so NVENC cannot exhaust the decoder's frame pool.
    result[result.index("-vf") + 1] = "scale_cuda=format=nv12:passthrough=0"
    return result
