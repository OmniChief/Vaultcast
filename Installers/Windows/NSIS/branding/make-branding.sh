#!/usr/bin/env sh
# Regenerates the installer artwork from the app mark (Application/assets/img/mark.svg).
# Needs rsvg-convert and ImageMagick. The outputs are committed, so builds do not run this.
set -eu
cd "$(dirname "$0")"
mark=../../../../Application/assets/img/mark.svg
bg='#0b1018'; panel='#101925'; accent='#e1b77b'
for s in 16 24 32 48 64 128 256; do rsvg-convert -w $s -h $s "$mark" -o "mark-$s.png"; done
convert mark-16.png mark-24.png mark-32.png mark-48.png mark-64.png mark-128.png mark-256.png vaultcast.ico
# Welcome/finish side panel, 164x314 (MUI2 default size).
convert -size 164x314 "gradient:$panel-$bg" \
  \( mark-128.png -resize 96x96 \) -gravity north -geometry +0+46 -composite \
  -fill "$accent" -draw "rectangle 46,166 118,168" \
  -fill '#f2d6ad' -font DejaVu-Sans-Bold -pointsize 19 -gravity north -annotate +0+180 'Vaultcast' \
  -fill '#9fb0c4' -font DejaVu-Sans -pointsize 10 -annotate +0+208 'Your collection,' -annotate +0+222 'your space' \
  -type TrueColor BMP3:wizard.bmp
# Page header, 150x57, sits on the right of a white header.
convert -size 150x57 xc:white \( mark-48.png -resize 44x44 \) -gravity east -geometry +8+0 -composite \
  -fill "$panel" -font DejaVu-Sans-Bold -pointsize 15 -gravity east -annotate +58+0 'Vaultcast' \
  -type TrueColor BMP3:header.bmp
rm -f mark-*.png
