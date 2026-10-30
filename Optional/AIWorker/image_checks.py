"""Small local checks for failed image decodes before reporting generation success."""
import io


class BlankGenerationError(RuntimeError):
    pass


def check_image(content, capability=False):
    try:
        from PIL import Image
    except ImportError as error:
        raise RuntimeError("Image checks need Pillow. Run start-worker.cmd to install the updated contributor packages.") from error
    with Image.open(io.BytesIO(content)) as original:
        original.load()
        if original.mode == "RGBA" and original.getchannel("A").getextrema()[1] == 0:
            raise BlankGenerationError("InvokeAI returned a completely transparent image.")
        image = original.convert("RGB")
        ranges = image.getextrema()
        if max(high for low, high in ranges) <= 1:
            raise BlankGenerationError("InvokeAI returned a fully black image. Generation failed; the image was not uploaded.")
        if capability and all(high - low <= 1 for low, high in ranges):
            raise BlankGenerationError("The capability image is a solid colour; this computer has not passed generation verification.")
