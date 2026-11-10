<?php

namespace ErnestDefoe\HypeMarket\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;

/** The /hype page as the server sends it: a real title, canonical URL and description. No queries. */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-hype-market.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('hype');
        $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-hype-market.forum.page.description');

        return $document;
    }
}
