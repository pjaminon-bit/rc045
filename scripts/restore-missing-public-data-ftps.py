#!/usr/bin/env python3
"""FTPS: uitsluitend ontbrekende JSON-bestanden + 404.shtml toevoegen.
Bestaande bestanden nooit overschrijven of verwijderen.
"""
import hashlib
import io
import os
from ftplib import FTP_TLS
from pathlib import Path
import ssl

HOST = "ams101.yourwebhoster.com"
BASE = Path(".rc045-restore")
PUBLISH = os.environ.get("RC045_PUBLISH") == "true"
assert os.environ.get("GITHUB_REPOSITORY") == "pjaminon-bit/rc045"
if PUBLISH:
    assert os.environ.get("GITHUB_REF") == "refs/heads/main", "Live publiceren mag alleen vanaf main"
assert BASE.is_dir()

expected = {
    "data": ["bedankt.json", "aanmelden.json", "fotoboek-pagina.json"],
    ".": ["404.shtml"],
}

def names(ftp):
    # NLST toont de momenteel aanwezige bestanden in deze specifieke map.
    return {Path(n.rstrip("/")).name for n in ftp.nlst()}

def verify_copy(ftp, name, content):
    buf = io.BytesIO()
    ftp.retrbinary("RETR " + name, buf.write)
    received = buf.getvalue()
    assert hashlib.sha256(received).digest() == hashlib.sha256(content).digest(), (
        "FTPS read-back checksum mismatch: " + name
    )

ftp = FTP_TLS(context=ssl.create_default_context(), timeout=45)
ftp.connect(HOST, 21)
ftp.login(os.environ["RC045_FTP_USERNAME"], os.environ["RC045_FTP_PASSWORD"])
ftp.prot_p()
ftp.set_pasv(True)
base_dir = ftp.pwd()
print("FTPS beveiligd verbonden; uitvoer:", "PUBLISH" if PUBLISH else "DRY-RUN")

try:
    for folder, entries in expected.items():
        ftp.cwd(base_dir)
        if folder != ".":
            ftp.cwd(folder)
        existing = names(ftp)

        for name in entries:
            local = BASE / folder / name
            raw = local.read_bytes()
            if name in existing:
                print("BESTAAT AL, ONGEMOEID:", folder + "/" + name)
                continue

            if not PUBLISH:
                print("ONTBREEKT, KLAAR VOOR HERSTEL:", folder + "/" + name)
                continue

            # Extra check direct voor upload; bestaande serverbestanden
            # mogen ook bij een onverwachte wijziging nooit verloren gaan.
            if name in names(ftp):
                print("INTUSSEN AANWEZIG, NIETS GEDAAN:", folder + "/" + name)
                continue

            ftp.storbinary("STOR " + name, io.BytesIO(raw))
            verify_copy(ftp, name, raw)
            print("AANGEMAAKT EN VIA FTPS TERUGGELEZEN:", folder + "/" + name,
                  "bytes:", len(raw))
    print("KLAAR. Bestaande serverbestanden niet gewijzigd.")
finally:
    try:
        ftp.quit()
    except Exception:
        ftp.close()
