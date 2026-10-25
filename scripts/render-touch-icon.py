"""Render the original Little Sparks home-screen mark (development only: Pillow)."""
from pathlib import Path
from PIL import Image, ImageDraw

SCALE = 6
canvas = Image.new('RGB', (180 * SCALE, 180 * SCALE), '#0c1318')
draw = ImageDraw.Draw(canvas)

def box(bounds, radius, color):
    draw.rounded_rectangle(tuple(v * SCALE for v in bounds), radius * SCALE, fill=color)

def ellipse(bounds, color):
    draw.ellipse(tuple(v * SCALE for v in bounds), fill=color)

# Quiet halo, orb antenna, soft mint shell and friendly dark face.
ellipse((22, 24, 158, 160), '#142b2d')
box((87, 30, 93, 54), 3, '#b9ebcf')
ellipse((80, 22, 100, 42), '#d2bdec')
box((28, 72, 43, 105), 6, '#8fc7b0')
box((137, 72, 152, 105), 6, '#8fc7b0')
box((39, 49, 141, 122), 23, '#b9ebcf')
box((51, 64, 129, 107), 15, '#15272d')
ellipse((65, 77, 76, 89), '#d8fbef')
ellipse((104, 77, 115, 89), '#d8fbef')
draw.arc(tuple(v * SCALE for v in (78, 84, 102, 99)), 12, 168, fill='#d8fbef', width=3*SCALE)
box((61, 126, 119, 151), 10, '#8fc7b0')
box((62, 153, 82, 160), 3, '#b9ebcf')
box((98, 153, 118, 160), 3, '#b9ebcf')
# A tiny lavender heart: love, however you plug in.
ellipse((81, 131, 91, 141), '#e0c8f6')
ellipse((89, 131, 99, 141), '#e0c8f6')
draw.polygon([(81*SCALE, 137*SCALE), (99*SCALE, 137*SCALE), (90*SCALE, 147*SCALE)], fill='#e0c8f6')

target = Path(__file__).resolve().parents[1] / 'public' / 'assets' / 'apple-touch-icon.png'
canvas.resize((180, 180), Image.Resampling.LANCZOS).save(target, optimize=True)
print('Rendered public/assets/apple-touch-icon.png (180 x 180, opaque RGB)')
