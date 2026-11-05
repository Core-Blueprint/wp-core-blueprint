"""FRG-T4-01C: real PHP verifier against constructed ZIP fixtures, no WordPress DB."""
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import warnings
import zipfile

ROOT = Path(__file__).resolve().parents[2]
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9gZyC9sAAAAASUVORK5CYII=")

PHP_DRIVER = r'''<?php
namespace {
    define('ABSPATH', '/');
    class WP_Error { public function __construct(public string $code, public string $message) {} }
    require_once $argv[1] . '/src/DataExchange/Foundation.php';
    require_once $argv[1] . '/src/DataExchange/ResourcePackageVerifier.php';
    $result = \CoreBlueprint\Core\DataExchange\ResourcePackageVerifier::verify_file($argv[2]);
    if ($result instanceof WP_Error) {
        echo json_encode(['valid' => false, 'error' => $result->code]);
    } else {
        echo json_encode(['valid' => true, 'result' => $result]);
    }
}
'''


class ResourcePackageVerifierTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.area = Path(self.temp.name)
        self.driver = self.area / "verify.php"
        self.driver.write_text(PHP_DRIVER)
        self.path = self.area / "resource-package.zip"

    def package(self, *, resource=PNG, mime="image/png", alter=None, names=None, method=zipfile.ZIP_DEFLATED):
        resource_hash = hashlib.sha256(resource).hexdigest()
        record_resource = {"key": "brand-logo", "media_type": mime, "size": len(resource), "sha256": resource_hash}
        document = {
            "format": "core-blueprint-data-exchange", "format_version": 1,
            "extension_id": "core-blueprint-reports", "entity": "template.report",
            "schema_version": 1, "exported_at": "2026-11-05T00:00:00Z",
            "records": [{"portable_id": "report-a", "resources": [record_resource], "document": {"resource_key": "brand-logo"}}],
        }
        document_bytes = json.dumps(document, separators=(",", ":")).encode()
        manifest = {
            "package_format": "core-blueprint-template-resources", "package_version": 1,
            "extension_id": "core-blueprint-reports", "entity": "template.report", "record_count": 1,
            "document": {"path": "data-exchange.json", "size": len(document_bytes), "sha256": hashlib.sha256(document_bytes).hexdigest()},
            "resources": [{"key": "brand-logo", "path": "resources/sha256/" + resource_hash, "media_type": mime, "size": len(resource), "sha256": resource_hash}],
        }
        if alter:
            alter(manifest, document)
            document_bytes = json.dumps(document, separators=(",", ":")).encode()
        payloads = names or [("manifest.json", json.dumps(manifest, separators=(",", ":")).encode()),
                             ("data-exchange.json", document_bytes),
                             ("resources/sha256/" + resource_hash, resource)]
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(self.path, "w", compression=method) as z:
                for name, body in payloads:
                    if isinstance(name, zipfile.ZipInfo):
                        name.compress_type = method
                    z.writestr(name, body)
        return resource_hash

    def verify(self):
        p = subprocess.run(["php", str(self.driver), str(ROOT), str(self.path)], text=True, capture_output=True)
        self.assertEqual(0, p.returncode, p.stderr)
        return json.loads(p.stdout)

    def deny(self):
        result = self.verify()
        self.assertFalse(result["valid"], result)
        self.assertEqual("cb_core_resource_package_invalid", result["error"])

    def test_valid_deflate_produces_bounded_projection_no_resource_bytes_or_paths(self):
        digest = self.package()
        out = self.verify()
        self.assertTrue(out["valid"], out)
        self.assertEqual(digest, out["result"]["resources"][0]["sha256"])
        self.assertEqual(hashlib.sha256(self.path.read_bytes()).hexdigest(), out["result"]["package_sha256"])
        self.assertEqual("template.report", out["result"]["entity"])
        self.assertNotIn("/tmp", json.dumps(out["result"]))
        self.assertNotIn("resource_bytes", out["result"])

    def test_valid_stored_zip(self):
        self.package(method=zipfile.ZIP_STORED)
        self.assertTrue(self.verify()["valid"])

    def test_tampered_resource_refuses(self):
        digest = self.package()
        data = self.path.read_bytes().replace(PNG[:8], b"not-aPNG")
        # deflate data is not necessarily stored raw; mutate compressed payload instead.
        offset = data.index(b"PK\x03\x04") + 30
        self.path.write_bytes(data[:offset] + bytes([data[offset] ^ 4]) + data[offset + 1:])
        self.deny()

    def test_manifest_document_digest_mismatch_refuses(self):
        self.package(alter=lambda m, d: m["document"].update({"sha256": "0" * 64}))
        self.deny()

    def test_duplicate_json_manifest_key_refuses(self):
        self.package()
        with zipfile.ZipFile(self.path) as z:
            contents = [(info.filename, z.read(info.filename)) for info in z.infolist()]
        contents[0] = (contents[0][0], contents[0][1].replace(
            b'"package_version":1,', b'"package_version":1,"package_version":1,', 1))
        self.package(names=contents)
        self.deny()

    def test_unknown_manifest_field_refuses(self):
        self.package(alter=lambda m, d: m.update({"issuer": "external"}))
        self.deny()

    def test_record_resource_mismatch_refuses(self):
        self.package(alter=lambda m, d: d["records"][0]["resources"][0].update({"key": "altered"}))
        self.deny()

    def test_wrong_mime_refuses(self):
        self.package(mime="image/jpeg")
        self.deny()

    def test_zip_duplicate_name_refuses(self):
        self.package(names=[("manifest.json", b"one"), ("manifest.json", b"two"), ("data-exchange.json", b"other")])
        self.deny()

    def test_zip_traversal_refuses(self):
        self.package(names=[("../manifest.json", b"one"), ("data-exchange.json", b"two")])
        self.deny()

    def test_zip_extra_member_refuses(self):
        self.package()
        with zipfile.ZipFile(self.path, "a") as z:
            z.writestr("unexpected.txt", b"no")
        self.deny()

    def test_symlink_member_refuses(self):
        f = zipfile.ZipInfo("manifest.json")
        f.create_system = 3
        f.external_attr = (0o120777 << 16)
        self.package(names=[(f, b"target"), ("data-exchange.json", b"two")])
        self.deny()

    def test_local_filename_mismatch_refuses(self):
        self.package()
        content = self.path.read_bytes()
        self.path.write_bytes(content.replace(b"manifest.json", b"Manifest.json", 1))
        self.deny()

    def test_local_header_crc_mismatch_refuses(self):
        self.package()
        content = bytearray(self.path.read_bytes())
        content[14] ^= 1  # local header CRC32, not central directory
        self.path.write_bytes(content)
        self.deny()

    def test_encrypted_zip_bit_refuses(self):
        self.package()
        content = bytearray(self.path.read_bytes())
        content[6] |= 1  # local header general purpose flags
        self.path.write_bytes(content)
        self.deny()

    def test_symlinked_package_path_refuses(self):
        self.package()
        target = self.path
        self.path = self.area / "alias.zip"
        self.path.symlink_to(target)
        self.deny()

    def test_oversized_archive_refuses(self):
        self.path.write_bytes(b"X" * (24 * 1024 * 1024 + 1))
        self.deny()


if __name__ == "__main__":
    unittest.main()
