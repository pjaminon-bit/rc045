#!/usr/bin/env python3
"""Cache recognizable station artwork locally, with labeled fallback designs.

Only the artwork files in assets/radio/logos/themes/ are generated. Track
metadata, audio and album/artist artwork are deliberately out of scope.
"""
import hashlib
import io
import json
import math
import pathlib
import re
import urllib.parse
import urllib.request
from PIL import Image, ImageDraw, ImageFont, ImageOps, UnidentifiedImageError

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "scripts/radio-theme-logo-sources.json"
OUTPUT = ROOT / "assets/radio/logos/themes"
SOURCES = json.loads(SOURCE.read_text(encoding="utf-8"))["assets"]
ALLOWED = {"www.radiozenders.fm", "radioflow.nl", "api.mediahuisradio.nl",
           "www.efteling.com", "pinguinradio.com"}
MAX_DOWNLOAD = 4_000_000
PALETTE = {"538":("#5a0cdb","#ffffff"), "qmusic":("#db1648","#ffffff"),
           "radio10":("#14693d","#ffffff"), "slam":("#bb086b","#ffffff"),
           "100nl":("#bc521d","#ffffff"), "veronica":("#1166a4","#ffffff"),
           "pinguin":("#173039","#ffffff"), "kink":("#263139","#ffffff"),
           "skyradio":("#bb2939","#ffffff"), "decibel":("#bf202b","#ffffff"),
           "classicnl":("#20244b","#ffffff"), "efteling":("#94214a","#ffffff")}
FONT_PATHS = ["/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
              "/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf"]

def font(size):
    for path in FONT_PATHS:
        if pathlib.Path(path).exists():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()

def web_image(url):
    target = urllib.parse.urlparse(url)
    if target.scheme != "https" or target.netloc not in ALLOWED:
        raise ValueError("Rejected unknown image host")
    req = urllib.request.Request(url, headers={
        "User-Agent":"Mozilla/5.0 (compatible; RadioLogoCache/1.0)",
        "Accept":"image/avif,image/webp,image/png,image/jpeg,image/svg+xml,*/*;q=0.8"
    })
    with urllib.request.urlopen(req, timeout=22) as response:
        final = urllib.parse.urlparse(response.url)
        if final.scheme != "https" or final.netloc not in ALLOWED:
            raise ValueError("Redirect out of allowlist")
        data = response.read(MAX_DOWNLOAD + 1)
    if not data or len(data) > MAX_DOWNLOAD:
        raise ValueError("Image too large or empty")
    if data.lstrip().startswith((b"<svg",b"<?xml")):
        import cairosvg
        png = cairosvg.svg2png(bytestring=data, output_width=512)
        image = Image.open(io.BytesIO(png))
    else:
        image = Image.open(io.BytesIO(data))
        image.verify()
        image = Image.open(io.BytesIO(data))
    image.load()
    if min(image.size) < 90 or max(image.size) > 6000:
        raise ValueError("Image dimensions out of range: " + str(image.size))
    return image.convert("RGBA")

def local_image(path):
    file = ROOT / path
    if file.suffix.lower() == ".svg":
        import cairosvg
        return Image.open(io.BytesIO(
            cairosvg.svg2png(url=str(file),output_width=400))).convert("RGBA")
    return Image.open(file).convert("RGBA")

def normalize(img, max_size=512):
    canvas=Image.new("RGBA",(max_size,max_size),(0,0,0,0))
    # Keep existing artwork intact and avoid artificially cropping the logo.
    reduced=ImageOps.contain(img,(max_size,max_size),Image.Resampling.LANCZOS)
    canvas.alpha_composite(reduced,((max_size-reduced.width)//2,
                                    (max_size-reduced.height)//2))
    return canvas

def fallback_logo(item):
    group=item["group"]
    bg,fg=PALETTE.get(group,("#252b36","#ffffff"))
    base=local_image(item["base"])
    out=Image.new("RGBA",(512,512),bg)
    tile=ImageOps.contain(base,(370,318),Image.Resampling.LANCZOS)
    out.alpha_composite(tile,((512-tile.width)//2,(330-tile.height)//2))
    draw=ImageDraw.Draw(out)
    draw.rounded_rectangle((15,375,497,496),radius=18,fill=(0,0,0,105))
    title=item["title"]
    if len(title)>17:
        size=29
    elif len(title)>12:
        size=35
    else:
        size=45
    face=font(size)
    while draw.textbbox((0,0),title,font=face)[2] > 455 and size>17:
        size-=2
        face=font(size)
    bbox=draw.textbbox((0,0),title,font=face)
    text_width=bbox[2]-bbox[0]
    draw.text(((512-text_width)/2,412-bbox[1]),title,font=face,fill=fg)
    return out

def main():
    OUTPUT.mkdir(parents=True,exist_ok=True)
    details={}
    for key,item in SOURCES.items():
        if not re.fullmatch("[a-z0-9]+",key):
            raise ValueError("Invalid asset ID")
        mode="fallback"
        image=None
        reason=None
        if item["source"]:
            try:
                image=normalize(web_image(item["source"]))
                mode="source"
            except Exception as exc:
                reason=str(exc)[:180]
        if image is None:
            image=fallback_logo(item)
        path=OUTPUT / (key+".png")
        image.save(path,optimize=True)
        if path.stat().st_size < 1000:
            raise ValueError("Generated suspiciously small image: "+str(path))
        details[key]={"type":mode,"file":path.name,
                      "sha256":hashlib.sha256(path.read_bytes()).hexdigest(),
                      "bytes":path.stat().st_size}
        if reason: details[key]["fallback_reason"]=reason
        print(f'{key:21} {mode:8} {path.stat().st_size:7} bytes')
    (OUTPUT/"sources-generated.json").write_text(
        json.dumps(details,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print("Completed",len(details),"logos;",
          sum(d["type"]=="source" for d in details.values()),"source images;",
          sum(d["type"]=="fallback" for d in details.values()),"fallbacks")

if __name__=="__main__":
    main()
