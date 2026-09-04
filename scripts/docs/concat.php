<?php

/*
 * Builds one Markdown file from the guide's chapters for scripts/docs/build-pdf.sh.
 *
 *   php scripts/docs/concat.php [pages.json] > storage/app/docs-build/guide.md
 *
 * Emits a print stylesheet (make-pdf has no --css flag but passes <style> through), a
 * Contents page, then every chapter from resources/docs/manifest.php as an H1 (make-pdf
 * starts a new page at each H1) followed by its body with image links rewritten to be
 * relative to the build file.
 *
 * pages.json (from scripts/docs/toc-pages.php) maps heading text to page number. Without
 * it the Contents shows placeholders; build-pdf.sh runs twice so the second pass has real
 * numbers and identical pagination.
 */

$root = dirname(__DIR__, 2);
$manifest = require $root.'/resources/docs/manifest.php';
$pages = isset($argv[1]) && is_file($argv[1]) ? (array) json_decode((string) file_get_contents($argv[1]), true) : [];

echo <<<'CSS'
<style>
  h1 { color: #5d42b3; font-size: 26pt; margin-top: 0; padding-bottom: 6pt; border-bottom: 2px solid #e5e0f7; }
  h2 { color: #3b2a7a; font-size: 15pt; margin-top: 20pt; }
  h3 { font-size: 12pt; margin-top: 14pt; }
  p, li { font-size: 10.5pt; line-height: 1.5; }
  img { display: block; max-width: 100%; border: 1px solid #d8d8dc; border-radius: 6px; margin: 10pt 0 4pt; }
  img + em { display: block; text-align: center; font-style: normal; font-size: 9pt; color: #6b6b73; margin: 0 0 14pt; }
  blockquote { margin: 12pt 0; padding: 8pt 12pt; border-left: 4px solid #6b4fc4; background: #f4f1fc; border-radius: 0 6px 6px 0; break-inside: avoid; }
  blockquote p { margin: 0; font-size: 10pt; }
  table { border-collapse: collapse; width: 100%; font-size: 9.5pt; margin: 10pt 0; }
  th, td { text-align: left; padding: 5pt 7pt; border-bottom: 1px solid #e2e2e6; vertical-align: top; }
  th { background: #f4f1fc; color: #3b2a7a; }
  tr:nth-child(even) td { background: #fafafa; }
  table.contents { font-size: 10.5pt; margin-top: 14pt; break-inside: auto; page-break-inside: auto; }
  table.contents td { border-bottom: 1px dotted #d8d8dc; padding: 4pt 6pt; background: none; vertical-align: baseline; }
  table.contents tr.toc-chapter td { font-weight: 600; color: #3b2a7a; padding-top: 10pt; }
  table.contents tr.toc-section td:first-child { padding-left: 22pt; }
  table.contents td.page { text-align: right; width: 3em; color: #6b6b73; font-variant-numeric: tabular-nums; }
  code { font-size: 9.5pt; background: #f3f3f5; padding: 1pt 4pt; border-radius: 3px; }
  kbd { font-size: 9pt; border: 1px solid #c9c9cf; border-bottom-width: 2px; border-radius: 3px; padding: 0 4pt; background: #fff; }
  ol li, ul li { margin: 3pt 0; }
  hr { border: 0; border-top: 1px solid #e2e2e6; margin: 16pt 0; }
</style>

CSS;

$esc = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$page = fn (string $title) => isset($pages[$title]) ? (string) $pages[$title] : '–';

// Contents page: the intro, then every chapter and its sections with page numbers.
echo "# Contents\n\n";
echo "> **About this guide.** BrookeApp is billing and Fitness Wallet tracking for personal trainers. This guide walks through every screen, in the order you will meet it, with pictures from a demo account (\"Brooke Fitness\"). The same guide lives inside the app: click your name at the bottom of the sidebar, then **Documentation**.\n\n";
echo "<table class=\"contents\">\n";
foreach ($manifest as $chapter) {
    echo '<tr class="toc-chapter"><td>'.$esc($chapter['title']).'</td><td class="page">'.$page($chapter['title'])."</td></tr>\n";
    foreach (file($root.'/resources/docs/'.$chapter['file']) as $line) {
        if (preg_match('/^## (.+)$/', rtrim($line), $m)) {
            echo '<tr class="toc-section"><td>'.$esc($m[1]).'</td><td class="page">'.$page($m[1])."</td></tr>\n";
        }
    }
}
echo "</table>\n\n";

foreach ($manifest as $chapter) {
    $body = file_get_contents($root.'/resources/docs/'.$chapter['file']);
    $body = preg_replace('/\]\(\/images\/docs\/([^)\s]+)\)/', '](images/$1){width=full}', $body);

    echo '# '.$chapter['title']."\n\n";
    if (! empty($chapter['admin'])) {
        echo "> **Note:** This chapter is for the platform owner. Trainers do not see the Admin area.\n\n";
    }
    echo rtrim($body)."\n\n";
}
