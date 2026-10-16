#!/usr/bin/env python3
import os
import struct
import sys
import tempfile
import warnings


MAX_DIMENSION = 8000
MAX_PIXELS = MAX_DIMENSION * MAX_DIMENSION
MAX_PDF_PAGES = 20
MAX_PDF_PIXELS = 70_000_000
PDF_PAGE_DPI = 150
PDF_MAX_WIDTH = 2500
PDF_MAX_HEIGHT = 3500


def set_worker_limits():
    if os.name != "posix":
        return
    import resource

    resource.setrlimit(resource.RLIMIT_CPU, (45, 45))
    memory_limit = 1024 * 1024 * 1024
    resource.setrlimit(resource.RLIMIT_AS, (memory_limit, memory_limit))


def reject_trailing_image_payload(path, image_format):
    with open(path, "rb") as source:
        if image_format == "JPEG":
            source.seek(-2, os.SEEK_END)
            if source.read(2) != b"\xff\xd9":
                raise ValueError("invalid JPEG ending")
            return

        data = source.read()
    if not data.startswith(b"\x89PNG\r\n\x1a\n"):
        raise ValueError("invalid PNG signature")

    offset = 8
    saw_end = False
    while offset + 12 <= len(data):
        length = struct.unpack(">I", data[offset : offset + 4])[0]
        chunk_type = data[offset + 4 : offset + 8]
        end = offset + 12 + length
        if end > len(data):
            raise ValueError("truncated PNG")
        offset = end
        if chunk_type == b"IEND":
            if length != 0 or offset != len(data):
                raise ValueError("unexpected PNG trailing data")
            saw_end = True
            break
    if not saw_end:
        raise ValueError("missing PNG end marker")


def reject_heif_video_boxes(path):
    with open(path, "rb") as source:
        data = source.read()
    if len(data) < 12 or data[4:8] != b"ftyp":
        raise ValueError("invalid HEIF container")
    brand = data[8:12]
    if brand not in {
        b"heic",
        b"heix",
        b"heim",
        b"heis",
        b"hevc",
        b"hevx",
        b"mif1",
        b"msf1",
    }:
        raise ValueError("unsupported ISO-BMFF brand")

    offset = 0
    while offset + 8 <= len(data):
        box_size = struct.unpack(">I", data[offset : offset + 4])[0]
        box_type = data[offset + 4 : offset + 8]
        header_size = 8
        if box_size == 1:
            if offset + 16 > len(data):
                raise ValueError("invalid extended box")
            box_size = struct.unpack(">Q", data[offset + 8 : offset + 16])[0]
            header_size = 16
        elif box_size == 0:
            box_size = len(data) - offset
        if box_size < header_size or offset + box_size > len(data):
            raise ValueError("invalid ISO-BMFF box")
        if box_type == b"moov":
            raise ValueError("HEIF video component is not allowed")
        offset += box_size
    if offset != len(data):
        raise ValueError("invalid trailing ISO-BMFF data")


def image_dimensions_are_allowed(width, height):
    return (
        width > 0
        and height > 0
        and width <= MAX_DIMENSION
        and height <= MAX_DIMENSION
        and width * height <= MAX_PIXELS
    )


def sanitize_image(source_path, output_path):
    from PIL import Image, ImageOps
    from pillow_heif import open_heif

    Image.MAX_IMAGE_PIXELS = MAX_PIXELS
    warnings.simplefilter("error", Image.DecompressionBombWarning)

    with open(source_path, "rb") as source:
        signature = source.read(12)
    is_heif = len(signature) >= 12 and signature[4:8] == b"ftyp"

    if is_heif:
        reject_heif_video_boxes(source_path)
        heif = open_heif(source_path)
        if len(heif) != 1:
            raise ValueError("multi-image HEIF is not allowed")
        info = heif[0].info
        if (
            info.get("aux")
            or info.get("depth_images")
            or info.get("thumbnails")
            or heif.info.get("entity_groups")
        ):
            raise ValueError("auxiliary HEIF image data is not allowed")
        image = heif[0].to_pillow()
        if not image_dimensions_are_allowed(*image.size):
            raise ValueError("image dimensions are not allowed")
        image.load()
        image = ImageOps.exif_transpose(image)
        output_format = "JPEG"
    else:
        with Image.open(source_path) as probe:
            input_format = probe.format
            if input_format not in {"JPEG", "PNG"}:
                raise ValueError("unsupported decoded image format")
            if not image_dimensions_are_allowed(*probe.size):
                raise ValueError("image dimensions are not allowed")
            reject_trailing_image_payload(source_path, input_format)
            probe.verify()

        with Image.open(source_path) as decoded:
            decoded.load()
            image = ImageOps.exif_transpose(decoded.copy())
            output_format = input_format

    if not image_dimensions_are_allowed(*image.size):
        raise ValueError("oriented image dimensions are not allowed")

    if output_format == "JPEG":
        image.convert("RGB").save(
            output_path,
            format="JPEG",
            quality=90,
            optimize=True,
            exif=b"",
        )
    else:
        image.save(output_path, format="PNG", optimize=True)
    image.close()


def find_forbidden_pdf_object(value, visited):
    from pypdf.generic import ArrayObject, DictionaryObject, IndirectObject

    forbidden = {
        "/JavaScript",
        "/JS",
        "/OpenAction",
        "/AA",
        "/Launch",
        "/EmbeddedFile",
        "/RichMedia",
        "/XFA",
    }

    if isinstance(value, IndirectObject):
        identity = (value.idnum, value.generation)
        if identity in visited:
            return False
        visited.add(identity)
        return find_forbidden_pdf_object(value.get_object(), visited)
    if isinstance(value, DictionaryObject):
        if any(str(key) in forbidden for key in value.keys()):
            return True
        return any(find_forbidden_pdf_object(item, visited) for item in value.values())
    if isinstance(value, ArrayObject):
        return any(find_forbidden_pdf_object(item, visited) for item in value)
    return False


def validate_pdf(path):
    from pypdf import PdfReader

    with open(path, "rb") as source:
        raw = source.read()
    final_eof = raw.rfind(b"%%EOF")
    if final_eof < 0 or raw[final_eof + 5 :].strip(b"\x00\t\n\f\r "):
        raise ValueError("invalid PDF ending or appended payload")

    import re

    if re.search(
        rb"/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia|XFA)\b",
        raw,
    ):
        raise ValueError("active PDF content is not allowed")

    reader = PdfReader(path, strict=True)
    if reader.is_encrypted:
        raise ValueError("encrypted PDF is not allowed")
    if len(reader.pages) < 1 or len(reader.pages) > MAX_PDF_PAGES:
        raise ValueError("PDF page count is not allowed")
    if find_forbidden_pdf_object(reader.trailer, set()):
        raise ValueError("active PDF content is not allowed")
    return len(reader.pages)


def sanitize_pdf(source_path, output_path):
    import img2pdf
    import pypdfium2 as pdfium
    from PIL import Image

    page_count = validate_pdf(source_path)
    document = pdfium.PdfDocument(source_path)
    if len(document) != page_count:
        raise ValueError("inconsistent PDF page count")

    rendered_paths = []
    total_pixels = 0
    with tempfile.TemporaryDirectory(prefix="nx-pdf-", dir=os.path.dirname(output_path)) as work:
        for index in range(page_count):
            page = document[index]
            width_points, height_points = page.get_size()
            scale = min(
                PDF_PAGE_DPI / 72,
                PDF_MAX_WIDTH / width_points,
                PDF_MAX_HEIGHT / height_points,
            )
            width = max(1, int(width_points * scale))
            height = max(1, int(height_points * scale))
            total_pixels += width * height
            if (
                width > MAX_DIMENSION
                or height > MAX_DIMENSION
                or total_pixels > MAX_PDF_PIXELS
            ):
                raise ValueError("PDF raster dimensions are not allowed")

            bitmap = page.render(scale=scale, rotation=0)
            image = bitmap.to_pil().convert("RGB")
            rendered_path = os.path.join(work, f"page-{index:02d}.jpg")
            image.save(rendered_path, format="JPEG", quality=90, optimize=True, exif=b"")
            image.close()
            page.close()
            rendered_paths.append(rendered_path)

        with open(output_path, "wb") as output:
            img2pdf.convert(*rendered_paths, outputstream=output)
        document.close()

    if validate_pdf(output_path) != page_count:
        raise ValueError("sanitized PDF validation failed")


def main():
    if len(sys.argv) != 4:
        return 2
    mode, source_path, output_path = sys.argv[1:]
    try:
        set_worker_limits()
        if mode == "image":
            sanitize_image(source_path, output_path)
        elif mode == "pdf":
            sanitize_pdf(source_path, output_path)
        else:
            return 2
        return 0
    except Exception:
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
