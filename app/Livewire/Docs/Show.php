<?php

namespace App\Livewire\Docs;

use App\Support\UserGuide;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public string $slug = '';

    public function mount(UserGuide $guide, ?string $chapter = null): void
    {
        $user = auth()->user();

        $this->slug = $chapter ?? $guide->chapters($user)[0]['slug'];

        $guide->find($this->slug, $user);
    }

    public function render(UserGuide $guide): View
    {
        $user = auth()->user();
        $chapter = $guide->find($this->slug, $user);
        [$previous, $next] = $guide->neighbours($chapter, $user);

        return view('livewire.docs.show', [
            'chapters' => $guide->chapters($user),
            'chapter' => $chapter,
            'html' => $guide->render($chapter),
            'headings' => $guide->headings($chapter),
            'previous' => $previous,
            'next' => $next,
            'hasPdf' => is_file($guide->pdfPath()),
        ])->title($chapter['title'].' · Documentation');
    }
}
