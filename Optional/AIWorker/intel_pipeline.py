"""Keep Intel decoding, pixel conversion and H.264 encoding on Quick Sync."""


def accelerated_command(command, video, runtime=None):
    decoders = {"h264": "h264_qsv", "hevc": "hevc_qsv", "mpeg2video": "mpeg2_qsv",
                "vc1": "vc1_qsv", "vp9": "vp9_qsv", "av1": "av1_qsv"}
    decoder = decoders.get(video.get("codec_name"))
    if (not decoder or video.get("pix_fmt") not in ("yuv420p", "yuv420p10le", "nv12", "p010le")
            or not video.get("width") or not video.get("height")
            or int(video["width"]) % 2 or int(video["height"]) % 2):
        return None
    result = list(command)
    if runtime:
        result[0] = runtime
    at = result.index("-i")
    result[at:at] = ["-hwaccel", "qsv", "-hwaccel_output_format", "qsv", "-codec:v", decoder]
    result[result.index("-pix_fmt") + 1] = "qsv"
    result[result.index("-vf") + 1] = "vpp_qsv=format=nv12"
    return result


def needs_compatibility_runtime(error):
    # FFmpeg's newer D3D11 frame allocation fails on some legacy Intel drivers.
    # Only this observed failure warrants the private, checksum-pinned runtime.
    return "could not create the texture (80070057)" in str(error).lower()
