#!/usr/bin/env python3
"""Inventariseer en kopieer uitsluitend vaste RC045-afbeeldingen vanaf de FTPS-host.

De pagina's bepalen zelf de bronlijst. Mappen voor dynamische uploads worden
nooit doorzocht, gedownload of gewijzigd. De FTPS-verbinding is alleen-lezen.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import os
from pathlib import Path
import re
import ssl
import sys
from ftplib import FTP_TLS, all_errors

ROOT = Path(__file__).resolve().parent.parent
HOST = "ams101.yourwebhoster.com"
PAGE_EXTENSIONS = {".php", ".html", ".css", ".js"}
# Alleen afbeeldingen direct onder images/ zijn bedoeld voor de vaste vormgeving.
STATIC_REFERENCE = re.compile(
    r"(?<![\w/])images/([a-zA-Z0-9][a-zA-Z0-9_-]*\.(?:jpe?g|png|webp|gif|svg))\b",
    re.IGNORECASE,
)
MAX_IMAGE_BYTES = 30 * 1024 * 1024
MAX_TOTAL_BYTES = 350 * 1024 * 1024


def referenced_assets() -> dict[str, set[str]]:
    links: dict[str, set[str]] = {}
    for source in ROOT.iterdir():
        if not source.is_file() or source.suffix.lower() not in PAGE_EXTENSIONS:
            continue
        content = source.read_text(encoding="utf-8")
        for hit in STATIC_REFERENCE.finditer(content):
            links.setdefault(hit.group(1), set()).add(source.name)
    return dict(sorted(links.items()))


def connect() -> FTP_TLS:
    ftp = FTP_TLS(context=ssl.create_default_context(), timeout=60)
    ftp.connect(HOST, 21)
    ftp.login(os.environ["RC045_FTP_USERNAME"], os.environ["RC045_FTP_PASSWORD"])
    ftp.prot_p()
    ftp.set_pasv(True)
    # De FTP-gebruiker begint in public_html.
    root_names = {Path(p).name for p in ftp.nlst()}
    if "index.php" not in root_names:
        raise RuntimeError("FTPS-root is niet de verwachte RC045-public_html")
    ftp.cwd("images")
    ftp.voidcmd("TYPE I")
    return ftp


def verify_image(data: bytes, name: str) -> None:
    if not data or len(data) > MAX_IMAGE_BYTES:
        raise ValueError("Lege of te grote afbeelding: " + name)
    lower = name.lower()
    if lower.endswith((".jpg", ".jpeg")) and not (data[:3] == b"\xff\xd8\xff" and data[-2:] == b"\xff\xd9"):
        raise ValueError("Ongeldig JPEG-bestand: " + name)
    if lower.endswith(".png") and data[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError("Ongeldig PNG-bestand: " + name)
    if lower.endswith(".webp") and not (data[:4] == b"RIFF" and data[8:12] == b"WEBP"):
        raise ValueError("Ongeldig WEBP-bestand: " + name)
    if lower.endswith(".gif") and data[:6] not in {b"GIF87a", b"GIF89a"}:
        raise ValueError("Ongeldig GIF-bestand: " + name)
    if lower.endswith(".svg"):
        # SVG uitsluitend vanaf vertrouwde server. Niet uitvoeren of renderen.
        if b"<svg" not in data[:4096].lower():
            raise ValueError("Geen geldig SVG-document: " + name)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--mode", choices=("inventory", "import"), default="inventory")
    args = ap.parse_args()

    expected = referenced_assets()
    if len(expected) < 20:
        raise RuntimeError("Onverwacht weinig vaste afbeeldingsverwijzingen; afgebroken")
    if args.mode == "import" and os.environ.get("GITHUB_REF") != "refs/heads/fix/static-site-images-20261030":
        raise RuntimeError("Import uitsluitend op gecontroleerde feature-branch")
    print(f"Vaste afbeeldingen in pagina-code: {len(expected)}")

    ftp = connect()
    copied: list[tuple[str, int, str]] = []
    exists: list[str] = []
    absent: list[str] = []
    transferred = 0
    try:
        server_files = {Path(p).name for p in ftp.nlst()}
        for name, sources in expected.items():
            target = ROOT / "images" / name
            if target.is_file():
                exists.append(name)
                continue
            if name not in server_files:
                absent.append(name)
                print(f"ONTBREEKT OP SERVER: images/{name} (gebruik: {', '.join(sorted(sources))})")
                continue
            try:
                size = ftp.size(name)
            except all_errors:
                size = None
            if size is None or size <= 0 or size > MAX_IMAGE_BYTES:
                raise RuntimeError(f"Onveilig of onbekend bestandsformaat/grootte: {name}: {size}")
            transferred += size
            if transferred > MAX_TOTAL_BYTES:
                raise RuntimeError("Maximale totale importgrootte overschreden")

            if args.mode == "inventory":
                print(f"AANWEZIG OP SERVER, NIET IN GITHUB: images/{name} ({size} bytes)")
                continue

            # Lees bron als bytes; geen wijzigingen of hercompressie.
            buffer = io.BytesIO()
            ftp.retrbinary("RETR " + name, buffer.write, blocksize=131072)
            data = buffer.getvalue()
            if len(data) != size:
                raise RuntimeError(f"Afwijkende servergrootte voor images/{name}")
            verify_image(data, name)
            target.parent.mkdir(parents=True, exist_ok=True)
            with target.open("xb") as fh:
                fh.write(data)
            digest = hashlib.sha256(data).hexdigest()
            copied.append((name, len(data), digest))
            print(f"VEILIG OVERGENOMEN: images/{name} ({len(data)} bytes, SHA256 {digest})")
    finally:
        try:
            ftp.quit()
        except all_errors:
            ftp.close()

    print(f"OVERZICHT: referenties={len(expected)} al_in_repo={len(exists)} "
          f"van_server_overgenomen={len(copied)} op_server_ontbrekend={len(absent)} "
          f"potentiele_bytes={transferred}")
    if absent:
        print("ONTBREKENDE_ASSETS=" + ",".join(absent))
    if args.mode == "import" and not copied:
        print("Niets om in GitHub op te slaan.")
    # Bij bewuste hiaten blijft de import succesvol. De rapportage vermeldt
    # ze expliciet zodat geen vervangende afbeelding wordt verzonnen.


if __name__ == "__main__":
    main()
