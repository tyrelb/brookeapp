#!/usr/bin/env bash
# Builds resources/docs/BrookeApp-User-Guide.pdf from the Markdown chapters and screenshots,
# using gstack's make-pdf (Chromium). Run after scripts/docs/screenshots.sh or after editing
# any chapter, then commit the PDF.
#
# The build runs twice: the first pass finds the page every heading lands on (pdftotext),
# the second pass prints those numbers in the Contents table. Both passes have the same
# number of Contents rows, so the pagination is identical.
#
#   scripts/docs/build-pdf.sh            # build
#   scripts/docs/build-pdf.sh preview    # render pass 1 to HTML and open it in the browser

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

P="${MAKE_PDF_BIN:-$HOME/.claude/skills/gstack/make-pdf/dist/pdf}"
if [ ! -x "$P" ]; then
    echo "make-pdf not found at $P (install gstack: https://github.com/garrytan/gstack)" >&2
    exit 1
fi
command -v pdftotext >/dev/null || { echo "pdftotext (poppler) is required for Contents page numbers" >&2; exit 1; }

BUILD=storage/app/docs-build
OUT=resources/docs/BrookeApp-User-Guide.pdf
mkdir -p "$BUILD"

# Images are referenced as images/<name>.png relative to the build file.
rm -rf "$BUILD/images"; cp -R public/images/docs "$BUILD/images"

BUILD_ID="$(git rev-parse --short HEAD 2>/dev/null || echo dev)"   # make-pdf prints the date itself

generate() {
    "$P" generate --cover --strict --no-confidential \
        --page-size letter --margins 0.85in \
        --title "BrookeApp User Guide" \
        --author "Brooke Fitness · build $BUILD_ID" \
        "$1" "$2" >/dev/null
}

php scripts/docs/concat.php > "$BUILD/guide.md"
if [ "${1:-}" = "preview" ]; then
    exec "$P" preview "$BUILD/guide.md"
fi

echo "pass 1: finding page numbers"
generate "$BUILD/guide.md" "$BUILD/pass1.pdf"
php scripts/docs/toc-pages.php "$BUILD/pass1.pdf" > "$BUILD/pages.json"

echo "pass 2: final PDF"
php scripts/docs/concat.php "$BUILD/pages.json" > "$BUILD/guide.md"
generate "$BUILD/guide.md" "$OUT"

echo "built $OUT ($(du -h "$OUT" | cut -f1), $(pdfinfo "$OUT" | awk '/^Pages:/ {print $2}') pages)"
