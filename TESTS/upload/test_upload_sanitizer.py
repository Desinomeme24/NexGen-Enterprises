import importlib.util
import struct
import tempfile
import unittest
from pathlib import Path

from PIL import Image
from pypdf import PdfReader, PdfWriter
from pypdf.generic import DictionaryObject, NameObject, TextStringObject


ROOT = Path(__file__).resolve().parents[2]
WORKER_PATH = ROOT / "CODE" / "PHP" / "upload_sanitizer.py"
SPEC = importlib.util.spec_from_file_location("upload_sanitizer", WORKER_PATH)
sanitizer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(sanitizer)


class UploadSanitizerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def make_image(self, name, image_format, size=(32, 24), exif=None):
        path = self.directory / name
        image = Image.new("RGB", size, (30, 100, 180))
        options = {}
        if exif is not None:
            options["exif"] = exif
        image.save(path, format=image_format, **options)
        return path

    def test_jpeg_and_png_are_reencoded_without_exif(self):
        exif = Image.Exif()
        exif[274] = 6
        for image_format, extension in (("JPEG", "jpg"), ("PNG", "png")):
            with self.subTest(image_format=image_format):
                source = self.make_image(f"source.{extension}", image_format, exif=exif)
                output = self.directory / f"clean.{extension}"
                sanitizer.sanitize_image(source, output)
                with Image.open(output) as image:
                    self.assertEqual(image.format, image_format)
                    self.assertEqual(len(image.getexif()), 0)

    def test_jpeg_orientation_is_applied_before_metadata_removal(self):
        exif = Image.Exif()
        exif[274] = 6
        source = self.make_image("oriented.jpg", "JPEG", (30, 20), exif)
        output = self.directory / "oriented-clean.jpg"
        sanitizer.sanitize_image(source, output)
        with Image.open(output) as image:
            self.assertEqual(image.size, (20, 30))
            self.assertEqual(len(image.getexif()), 0)

    def test_image_dimensions_above_cap_are_rejected_before_decode(self):
        source = self.make_image("too-wide.png", "PNG", (8001, 1))
        with self.assertRaises(ValueError):
            sanitizer.sanitize_image(source, self.directory / "unused.png")

    def test_jpeg_polyglot_trailing_payload_is_rejected(self):
        source = self.make_image("polyglot.jpg", "JPEG")
        with source.open("ab") as output:
            output.write(b"\x00MZ<script>alert(1)</script>")
        with self.assertRaises(ValueError):
            sanitizer.sanitize_image(source, self.directory / "unused.jpg")

    def test_disallowed_input_formats_are_rejected(self):
        inputs = {
            "archive.zip": b"PK\x03\x04archive",
            "movie.mp4": b"\x00\x00\x00\x18ftypmp42",
            "movie.mov": b"\x00\x00\x00\x18ftypqt  ",
            "image.avif": b"\x00\x00\x00\x18ftypavif",
            "page.svg": b"<svg xmlns='http://www.w3.org/2000/svg'></svg>",
            "page.html": b"<script>alert(1)</script>",
            "program.exe": b"MZ" + b"\x00" * 20,
            "renamed-executable.jpg": b"MZ" + b"\x00" * 20,
            "renamed-html.jpg": b"<html><script>alert(1)</script></html>",
        }
        for name, contents in inputs.items():
            with self.subTest(name=name):
                source = self.directory / name
                source.write_bytes(contents)
                with self.assertRaises(Exception):
                    sanitizer.sanitize_image(source, self.directory / "unused.jpg")

    def make_pdf(self, name, pages=1, active=False, encrypted=False):
        path = self.directory / name
        writer = PdfWriter()
        for _ in range(pages):
            writer.add_blank_page(width=100, height=100)
        if active:
            action = DictionaryObject()
            action[NameObject("/S")] = NameObject("/JavaScript")
            action[NameObject("/JS")] = TextStringObject("app.alert('bad')")
            writer._root_object[NameObject("/OpenAction")] = action
        if encrypted:
            writer.encrypt("test-password")
        with path.open("wb") as output:
            writer.write(output)
        return path

    def test_valid_pdf_is_rasterized_and_remains_a_valid_pdf(self):
        source = self._jpeg_as_pdf()
        sanitized = self.directory / "sanitized.pdf"
        sanitizer.sanitize_pdf(source, sanitized)
        reader = PdfReader(sanitized, strict=True)
        self.assertEqual(len(reader.pages), 1)
        self.assertEqual(reader.pages[0].get("/Resources").get("/XObject") is not None, True)

    def _jpeg_as_pdf(self):
        import img2pdf

        image_path = self.make_image("page.jpg", "JPEG")
        pdf_path = self.directory / "image-page.pdf"
        pdf_path.write_bytes(img2pdf.convert(str(image_path)))
        return pdf_path

    def test_active_encrypted_and_oversized_page_pdfs_are_rejected(self):
        active = self.make_pdf("active.pdf", active=True)
        encrypted = self.make_pdf("encrypted.pdf", encrypted=True)
        too_many_pages = self.make_pdf("too-many.pdf", pages=21)
        for source in (active, encrypted, too_many_pages):
            with self.subTest(source=source.name):
                with self.assertRaises(ValueError):
                    sanitizer.validate_pdf(source)

    def test_pdf_with_appended_payload_is_rejected(self):
        source = self.make_pdf("appended.pdf")
        with source.open("ab") as output:
            output.write(b"<script>payload</script>")
        with self.assertRaises(ValueError):
            sanitizer.validate_pdf(source)

    def test_heif_video_box_is_rejected(self):
        source = self.directory / "video.heic"
        source.write_bytes(
            struct.pack(">I4s4sI", 16, b"ftyp", b"heic", 0)
            + struct.pack(">I4s", 8, b"moov")
        )
        with self.assertRaises(ValueError):
            sanitizer.reject_heif_video_boxes(source)

    def test_single_image_heif_is_converted_to_real_jpeg(self):
        import pillow_heif

        pillow_heif.register_heif_opener()
        source = self.directory / "source.heic"
        image = Image.new("RGB", (24, 18), (40, 120, 200))
        image.save(source, format="HEIF", quality=90)

        output = self.directory / "converted.jpg"
        sanitizer.sanitize_image(source, output)
        self.assertTrue(output.read_bytes().startswith(b"\xff\xd8\xff"))
        with Image.open(output) as converted:
            self.assertEqual(converted.format, "JPEG")
            self.assertEqual(converted.size, (24, 18))

    def test_heif_multiple_images_are_rejected(self):
        source = self.directory / "multi.heic"
        source.write_bytes(struct.pack(">I4s4sI", 16, b"ftyp", b"heic", 0))

        class FakeImage:
            info = {}

        class FakeHeif:
            info = {}

            def __len__(self):
                return 2

            def __getitem__(self, index):
                return FakeImage()

        import pillow_heif

        original_open = pillow_heif.open_heif
        pillow_heif.open_heif = lambda path: FakeHeif()
        try:
            with self.assertRaises(ValueError):
                sanitizer.sanitize_image(source, self.directory / "unused.jpg")
        finally:
            pillow_heif.open_heif = original_open


if __name__ == "__main__":
    unittest.main()
