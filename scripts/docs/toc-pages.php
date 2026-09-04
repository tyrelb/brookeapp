<?php

/*
 * Finds the page each chapter and section of the guide starts on, by reading the PDF's
 * text page by page with pdftotext. Used by scripts/docs/build-pdf.sh for the two-pass
 * build that puts real page numbers in the Contents table.
 *
 *   php scripts/docs/toc-pages.php resources/docs/BrookeApp-User-Guide.pdf > storage/app/docs-build/pages.json
 */

$pdf = $argv[1] ?? null;
if (! $pdf || ! is_file($pdf)) {
    fwrite(STDERR, "usage: toc-pages.php <file.pdf>\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$manifest = require $root.'/resources/docs/manifest.php';

// The headings we need, in document order: each chapter title (H1) and its "## " sections.
$wanted = [];
foreach ($manifest as $chapter) {
    $wanted[] = $chapter['title'];
    foreach (file($root.'/resources/docs/'.$chapter['file']) as $line) {
        if (preg_match('/^## (.+)$/', rtrim($line), $m)) {
            $wanted[] = $m[1];
        }
    }
}

$normalize = function (string $s): string {
    $s = str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"], ["'", "'", '"', '"', '-', '-'], $s);

    return trim(preg_replace('/\s+/', ' ', $s));
};

$pages = (int) trim((string) shell_exec('pdfinfo '.escapeshellarg($pdf).' | awk \'/^Pages:/ {print $2}\''));
$lines = []; // page => normalized lines
for ($p = 1; $p <= $pages; $p++) {
    $text = (string) shell_exec(sprintf('pdftotext -layout -f %d -l %d %s -', $p, $p, escapeshellarg($pdf)));
    $lines[$p] = array_values(array_filter(array_map($normalize, explode("\n", $text))));
}

// Skip the cover and the Contents pages: the first page whose first body line is the
// first chapter title is where the chapters start.
$first = $normalize($wanted[0]);
$start = 1;
for ($p = 1; $p <= $pages; $p++) {
    $body = array_values(array_filter($lines[$p], fn ($l) => $l !== 'BrookeApp User Guide'));
    if (($body[0] ?? null) === $first) {
        $start = $p;
        break;
    }
}

$result = [];
$cursor = $start;
foreach ($wanted as $title) {
    $needle = $normalize($title);
    $found = null;
    for ($p = $cursor; $p <= $pages; $p++) {
        if (in_array($needle, $lines[$p], true)) {
            $found = $p;
            break;
        }
    }
    if ($found === null) {
        fwrite(STDERR, "not found: {$title}\n");
    } else {
        $cursor = $found; // headings are in order, so never look backwards
    }
    $result[$title] = $found;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
