<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;

/**
 * The in-app user guide: an ordered list of Markdown chapters in resources/docs,
 * rendered to HTML for the Documentation page and concatenated into the PDF.
 *
 * @phpstan-type Chapter array{slug: string, title: string, summary: string, file: string, admin: bool}
 */
class UserGuide
{
    /**
     * Chapters the given user may read, in reading order.
     *
     * @return list<Chapter>
     */
    public function chapters(?User $user = null): array
    {
        $isAdmin = (bool) $user?->isAdmin();

        return array_values(array_filter($this->all(), fn (array $chapter) => ! $chapter['admin'] || $isAdmin));
    }

    /**
     * Every chapter, including admin-only ones (used by the PDF build).
     *
     * @return list<Chapter>
     */
    public function all(): array
    {
        return array_map(fn (array $chapter) => $chapter + ['admin' => false, 'summary' => ''], require $this->path('manifest.php'));
    }

    /**
     * @return Chapter
     */
    public function find(string $slug, ?User $user = null): array
    {
        foreach ($this->chapters($user) as $chapter) {
            if ($chapter['slug'] === $slug) {
                return $chapter;
            }
        }

        abort(404);
    }

    /**
     * The chapters before and after the given one, for the footer links.
     *
     * @param  Chapter  $chapter
     * @return array{0: ?Chapter, 1: ?Chapter}
     */
    public function neighbours(array $chapter, ?User $user = null): array
    {
        $chapters = $this->chapters($user);
        $index = array_search($chapter['slug'], array_column($chapters, 'slug'), true);

        return [
            $index > 0 ? $chapters[$index - 1] : null,
            $index !== false && $index < count($chapters) - 1 ? $chapters[$index + 1] : null,
        ];
    }

    /**
     * @param  Chapter  $chapter
     */
    public function markdown(array $chapter): string
    {
        return (string) file_get_contents($this->path($chapter['file']));
    }

    /**
     * Render a chapter to HTML. Cached per file modification time, so editing a chapter
     * shows up immediately in development and never re-parses in production.
     *
     * @param  Chapter  $chapter
     */
    public function render(array $chapter): HtmlString
    {
        $file = $this->path($chapter['file']);
        $key = 'user-guide:'.$chapter['slug'].':'.(file_exists($file) ? filemtime($file) : 0);

        $html = Cache::remember($key, now()->addDay(), fn () => Str::markdown($this->markdown($chapter), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'insert' => 'none',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'apply_id_to_heading' => true,
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
        ], [new HeadingPermalinkExtension]));

        return new HtmlString($html);
    }

    /**
     * The second-level headings of a chapter, for the "In this chapter" links.
     *
     * @param  Chapter  $chapter
     * @return list<array{id: string, text: string}>
     */
    public function headings(array $chapter): array
    {
        preg_match_all('/<h2[^>]*\bid="([^"]+)"[^>]*>(.*?)<\/h2>/s', (string) $this->render($chapter), $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => ['id' => $m[1], 'text' => trim(html_entity_decode(strip_tags($m[2])))], $matches);
    }

    /**
     * Root-relative image paths a chapter refers to, e.g. "/images/docs/clients.png".
     *
     * @param  Chapter  $chapter
     * @return list<string>
     */
    public function images(array $chapter): array
    {
        preg_match_all('/\]\((\/images\/docs\/[^)\s]+)\)/', $this->markdown($chapter), $matches);

        return array_values(array_unique($matches[1]));
    }

    public function pdfPath(): string
    {
        return $this->path('BrookeApp-User-Guide.pdf');
    }

    public function path(string $file = ''): string
    {
        return resource_path('docs'.($file !== '' ? '/'.$file : ''));
    }
}
