<?php

namespace App\Support;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Client emails are Markdown, and trainers write some of their text (business name, booking
 * instructions, invoice messages, notes). This renders a Markdown link or image as its plain
 * text unless it points back at this app, so that text cannot carry a hidden link or a tracking
 * pixel. Buttons and the header are HTML components and are not affected.
 *
 * Laravel's Markdown::withSecuredEncoding() would do this too, but only when the template was
 * compiled during mail rendering; `php artisan view:cache` compiles it without the protection.
 */
class PlainTextLinks implements ExtensionInterface, NodeRendererInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(Link::class, $this, 100);
        $environment->addRenderer(Image::class, $this, 100);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        // Links into the app itself (verification, password reset) keep their normal renderer.
        if ($node instanceof Link && str_starts_with($node->getUrl(), rtrim((string) config('app.url'), '/').'/')) {
            return null;
        }

        return $childRenderer->renderNodes($node->children());
    }
}
