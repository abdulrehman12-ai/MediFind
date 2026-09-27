#!/usr/bin/env python3
"""Check one untrusted PDF at a time. The process has no credentials and does not fetch URLs."""

import json
import os
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MAX_BYTES = 8 * 1024 * 1024
MAX_PAGES = 20
MAX_EDGE = 14400
CHECK_SECONDS = 8
TOKEN = os.environ.get("PDF_WORKER_TOKEN", "")
LOCK = threading.Lock()


def respond(handler: BaseHTTPRequestHandler, status: int, payload: dict) -> None:
    body = json.dumps(payload).encode("ascii")
    handler.send_response(status)
    handler.send_header("Content-Type", "application/json")
    handler.send_header("Content-Length", str(len(body)))
    handler.send_header("Cache-Control", "no-store")
    handler.end_headers()
    handler.wfile.write(body)


def run_qpdf(args: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["qpdf", *args],
        check=False,
        capture_output=True,
        text=True,
        timeout=CHECK_SECONDS,
    )


def qpdf_objects(document: dict) -> dict[str, object]:
    objects: dict[str, object] = {}
    parts = document.get("qpdf")
    if not isinstance(parts, list):
        return objects
    for part in parts:
        if not isinstance(part, dict):
            continue
        for key, value in part.items():
            if not isinstance(key, str) or not key.startswith("obj:"):
                continue
            body = value.get("value") if isinstance(value, dict) else None
            objects[key[4:]] = body if body is not None else value
    return objects


def as_number(item: object) -> float | None:
    if isinstance(item, bool) or not isinstance(item, (int, float)):
        return None
    return float(item)


def encryption_code(document: dict) -> str | None:
    """Use qpdf's encryption object. A content string is not an encryption dictionary."""
    encrypt = document.get("encrypt")
    if not isinstance(encrypt, dict):
        return "malformed"
    encrypted = encrypt.get("encrypted")
    parameters = encrypt.get("parameters")
    if not isinstance(encrypted, bool) or not isinstance(parameters, dict):
        return "malformed"
    method = parameters.get("method")
    file_method = parameters.get("filemethod")
    if encrypted or method != "none" or file_method != "none":
        return "encrypted"
    return None


def effective_box(objects: dict[str, object], reference: str, seen: set[str]) -> list[float] | None:
    if reference in seen:
        return None
    seen.add(reference)
    value = objects.get(reference)
    if isinstance(value, list):
        return box_values(value)
    if not isinstance(value, dict):
        return None
    box = value.get("/MediaBox")
    if isinstance(box, list):
        return box_values(box)
    if isinstance(box, str):
        return effective_box(objects, box, seen)
    parent = value.get("/Parent")
    if isinstance(parent, str):
        return effective_box(objects, parent, seen)
    return None


def box_values(box: list[object]) -> list[float] | None:
    if len(box) != 4:
        return None
    numbers: list[float] = []
    for item in box:
        number = as_number(item)
        if number is None:
            return None
        numbers.append(number)
    return numbers


def page_limits(document: dict) -> str | None:
    pages = document.get("pages")
    if not isinstance(pages, list) or len(pages) < 1:
        return "malformed"
    if len(pages) > MAX_PAGES:
        return "limit"
    objects = qpdf_objects(document)
    for page in pages:
        if not isinstance(page, dict) or not isinstance(page.get("object"), str):
            return "malformed"
        box = effective_box(objects, page["object"], set())
        if box is None:
            return "malformed"
        width = abs(box[2] - box[0])
        height = abs(box[3] - box[1])
        if width <= 0 or height <= 0 or width > MAX_EDGE or height > MAX_EDGE:
            return "limit"
    return None


def failure_code(result: subprocess.CompletedProcess[str]) -> str | None:
    """Exit 0 is a clean read. Exit 3 is accepted only for a missing page Resources repair."""
    if result.returncode < 0:
        return "limit"
    lowered = result.stderr.lower()
    if "invalid password" in lowered or "file is encrypted" in lowered or "requires a password" in lowered:
        return "encrypted"
    if result.returncode == 0:
        return None
    if result.returncode == 3:
        warnings = [line for line in lowered.splitlines() if line.startswith("warning:")]
        if warnings and all("resources is missing or invalid" in line for line in warnings):
            return None
        return "malformed"
    return classify(result.stderr, False, False)


def classify(stderr: str, timed_out: bool, killed: bool) -> str:
    if timed_out or killed:
        return "limit"
    lowered = stderr.lower()
    if "password" in lowered or "encrypt" in lowered:
        return "encrypted"
    if "exceed" in lowered or "too many" in lowered:
        return "limit"
    return "malformed"


def check_pdf(payload: bytes) -> tuple[int, dict]:
    if len(payload) < 5 or len(payload) > MAX_BYTES or not payload.startswith(b"%PDF-"):
        return 422, {"ok": False, "code": "malformed"}

    if not LOCK.acquire(blocking=False):
        return 503, {"ok": False, "code": "busy"}

    try:
        with tempfile.TemporaryDirectory(dir="/tmp") as directory:
            source = os.path.join(directory, "in.pdf")
            expanded = os.path.join(directory, "out.pdf")
            with open(source, "wb") as handle:
                handle.write(payload)

            try:
                checked = run_qpdf(["--check", source])
            except subprocess.TimeoutExpired:
                return 422, {"ok": False, "code": "limit"}

            checked_code = failure_code(checked)
            if checked_code is not None:
                return 422, {"ok": False, "code": checked_code}

            try:
                expanded_result = run_qpdf([
                    "--decode-level=all",
                    "--stream-data=uncompress",
                    "--static-id",
                    source,
                    expanded,
                ])
            except subprocess.TimeoutExpired:
                return 422, {"ok": False, "code": "limit"}

            expanded_code = failure_code(expanded_result)
            if expanded_code is not None:
                return 422, {"ok": False, "code": expanded_code}

            try:
                described = run_qpdf(["--json", "--json-stream-data=none", source])
            except subprocess.TimeoutExpired:
                return 422, {"ok": False, "code": "limit"}

            described_code = failure_code(described)
            if described_code is not None:
                return 422, {"ok": False, "code": described_code}

            try:
                document = json.loads(described.stdout)
            except json.JSONDecodeError:
                return 422, {"ok": False, "code": "malformed"}

            encrypted = encryption_code(document)
            if encrypted is not None:
                return 422, {"ok": False, "code": encrypted}

            limited = page_limits(document)
            if limited is not None:
                return 422, {"ok": False, "code": limited}

            pages = document.get("pages")
            return 200, {"ok": True, "pages": len(pages) if isinstance(pages, list) else 0}
    finally:
        LOCK.release()


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, format: str, *args) -> None:
        return

    def do_GET(self) -> None:
        if self.path != "/health":
            respond(self, 404, {"ok": False, "code": "malformed"})
            return
        respond(self, 200, {"ok": True})

    def do_POST(self) -> None:
        if self.path != "/check":
            respond(self, 404, {"ok": False, "code": "malformed"})
            return
        supplied = self.headers.get("X-Pdf-Worker-Token", "")
        if TOKEN == "" or supplied != TOKEN:
            respond(self, 401, {"ok": False, "code": "unauthorized"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if length < 1 or length > MAX_BYTES:
            respond(self, 422, {"ok": False, "code": "limit"})
            return
        payload = self.rfile.read(length)
        if len(payload) != length:
            respond(self, 422, {"ok": False, "code": "malformed"})
            return
        status, payload_json = check_pdf(payload)
        respond(self, status, payload_json)


def main() -> None:
    if TOKEN == "":
        raise SystemExit("PDF_WORKER_TOKEN is required")
    server = ThreadingHTTPServer(("0.0.0.0", 8080), Handler)
    server.serve_forever()


if __name__ == "__main__":
    main()
