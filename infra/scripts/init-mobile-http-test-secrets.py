#!/usr/bin/env python3
"""Create gitignored isolated HTTP test secrets without printing values."""

from __future__ import annotations

import base64
import os
import secrets
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGET = ROOT / "infra" / "mobile-http-test.secrets"


def six_digits() -> str:
    return f"{secrets.randbelow(1_000_000):06d}"


def main() -> None:
    if TARGET.exists():
        raise SystemExit("infra/mobile-http-test.secrets already exists")

    app_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode("ascii")

    body = "\n".join(
        [
            f"APP_KEY={app_key}",
            f"POSTGRES_PASSWORD={secrets.token_hex(24)}",
            f"POSTGRES_APP_PASSWORD={secrets.token_hex(24)}",
            f"POSTGRES_TEST_PASSWORD={secrets.token_hex(24)}",
            f"REDIS_TEST_PASSWORD={secrets.token_hex(24)}",
            f"OTP_TEST_HMAC_KEY={secrets.token_hex(32)}",
            f"LOCAL_OTP_CODE={six_digits()}",
            "",
        ]
    )
    old_umask = os.umask(0o177)
    try:
        TARGET.write_text(body, encoding="utf-8")
    finally:
        os.umask(old_umask)
    print("Wrote infra/mobile-http-test.secrets (values not printed).")


if __name__ == "__main__":
    main()
