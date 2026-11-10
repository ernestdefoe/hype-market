<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Market;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `hype-markets` JSON:API type, with no endpoints of its own: the forum
 * reads markets through the plain-JSON controllers. It exists because a
 * notification's subject must have a registered resource, or every
 * notification list 500s. Visibility follows the discussion.
 *
 * @extends AbstractDatabaseResource<Market>
 */
class MarketResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'hype-markets';
    }

    public function model(): string
    {
        return Market::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        /** @var Builder<Market> $query */
        $query->visibleTo(RequestUtil::getActor($context->request));
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            // The discussion's title as it is now (renames included); the copy taken at open only stands in once it's gone.
            Schema\Str::make('title')->get(fn (Market $m) => $m->discussion->title ?? $m->title),
            Schema\Integer::make('discussionId')->get(fn (Market $m) => $m->discussion_id),
        ];
    }
}
