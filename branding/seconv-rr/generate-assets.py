#!/usr/bin/env python3
"""Generate the SECONV-RR branding assets consumed by the `mod` (UI Branding) plugin.

Every file written to ``dist/`` matches, byte-for-byte in name and geometry, a file that
GLPI serves from ``public/pics``:

    logo-G-100-{black,grey,white}.png     53x53    collapsed side menu (navy background)
    logo-GLPI-100-{black,grey,white}.png  100x55   header / expanded side menu
    logo-GLPI-250-{black,grey,white}.png  250x138  login page card
    favicon.ico                           16/32/48 browser tab

GLPI 11.0 only reads three of the nine logos (`css/includes/_base.scss:65-72`): the two
`-white` sizes on the navy side menu, `logo-GLPI-250-black.png` on the light login card and
`logo-GLPI-250-white.png` under the dark palette. The other variants are generated anyway so
a future palette change cannot fall back to a GLPI-branded file.

Usage: python3 generate-assets.py
"""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

HERE = Path(__file__).parent
SOURCE = HERE / "src" / "brasao-roraima.png"
DIST = HERE / "dist"
FONT = Path("/usr/share/fonts/liberation/LiberationSans-Bold.ttf")

BRAND = "SECONV-RR"

# The variant name decides the text colour only: the coat of arms stays full colour, since
# that is how the state uses it.
TEXT_COLORS = {
    "black": (26, 26, 26, 255),
    "grey": (74, 74, 74, 255),
    "white": (255, 255, 255, 255),
}


def load_crest() -> Image.Image:
    crest = Image.open(SOURCE).convert("RGBA")
    bbox = crest.getchannel("A").getbbox()
    if bbox is None:
        raise ValueError(f"{SOURCE} is fully transparent")
    return crest.crop(bbox)


def scaled_crest(crest: Image.Image, max_w: int, max_h: int) -> Image.Image:
    ratio = min(max_w / crest.width, max_h / crest.height)
    size = (max(1, round(crest.width * ratio)), max(1, round(crest.height * ratio)))
    return crest.resize(size, Image.Resampling.LANCZOS)


def fit_font(text: str, max_w: int, max_h: int) -> ImageFont.FreeTypeFont:
    """Largest size at which `text` fits both bounds. Bisection would be overkill here."""
    chosen = ImageFont.truetype(str(FONT), 1)
    for size in range(1, max_h + 1):
        candidate = ImageFont.truetype(str(FONT), size)
        left, top, right, bottom = candidate.getbbox(text)
        if right - left > max_w or bottom - top > max_h:
            break
        chosen = candidate
    return chosen


def crest_only(crest: Image.Image, width: int, height: int, pad: int) -> Image.Image:
    canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    art = scaled_crest(crest, width - 2 * pad, height - 2 * pad)
    canvas.alpha_composite(art, ((width - art.width) // 2, (height - art.height) // 2))
    return canvas


def crest_with_brand(
    crest: Image.Image,
    width: int,
    height: int,
    color: tuple[int, int, int, int],
    pad: int,
    gap: int,
    crest_width_ratio: float,
) -> Image.Image:
    canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    art = scaled_crest(crest, round(width * crest_width_ratio), height - 2 * pad)
    canvas.alpha_composite(art, (pad, (height - art.height) // 2))

    text_left = pad + art.width + gap
    font = fit_font(BRAND, width - text_left - pad, round((height - 2 * pad) * 0.42))
    left, top, right, bottom = font.getbbox(BRAND)
    draw = ImageDraw.Draw(canvas)
    draw.text(
        (text_left - left, (height - (bottom - top)) // 2 - top),
        BRAND,
        font=font,
        fill=color,
    )
    return canvas


def main() -> None:
    DIST.mkdir(parents=True, exist_ok=True)
    crest = load_crest()

    small = crest_only(crest, 53, 53, pad=1)
    for variant, color in TEXT_COLORS.items():
        small.save(DIST / f"logo-G-100-{variant}.png")
        crest_with_brand(
            crest, 100, 55, color, pad=2, gap=4, crest_width_ratio=0.36
        ).save(DIST / f"logo-GLPI-100-{variant}.png")
        crest_with_brand(
            crest, 250, 138, color, pad=6, gap=10, crest_width_ratio=0.38
        ).save(DIST / f"logo-GLPI-250-{variant}.png")

    # GLPI ships public/pics/favicon.ico as a real 32x32 ICO; keep the container format and
    # add the other sizes browsers ask for.
    crest_only(crest, 48, 48, pad=0).save(
        DIST / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)]
    )

    for path in sorted(DIST.iterdir()):
        with Image.open(path) as generated:
            print(f"{path.name}: {generated.size[0]}x{generated.size[1]} {generated.format}")


if __name__ == "__main__":
    main()
