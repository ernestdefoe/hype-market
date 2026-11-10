# Hype Market: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center session for review before release.

## Design rules that exist for a reason

- **Prices only come from `Pricing::at()` over `Engagement::load()`**, never from a request. Pricing is pure: same events, cut-off and exclusions = same price. That is what makes settlement repeatable.
- 🚨 **Exclusions**: the author and EVERY backer are left out of the price for everyone (`Repricer::excluded`). Engagement drops low-trust accounts (unconfirmed; fewer than min posts OUTSIDE this discussion, so replies in the thread itself never make an account established; younger than min days *at the time they acted*), short and quote-only replies, hidden/private/unapproved posts, self-likes, and anything before `opened_at` (merged posts) or after `settles_at`.
- 🚨 **Pair cap** (`Settler::PAIR_CAP`): a member who already lifted 5 of a backer's paid calls in a season no longer counts toward that backer's calls. Contributors are stored on each paid position.
- **Payout**: growth counts only with `min_participants` distinct counted repliers, is never below 1×, minus the call's loss share (`hype_positions.loss`, the `loss_percent` setting copied at back time, 0 by default = nobody loses), clamped to 0…`Pricing::MAX_MULTIPLIER`. A position keeps the loss rate it was made with. Void/called-off markets always refund the full stake.
- **Backing** (`Backing`) locks the market row, then the member's `hype_accounts` row, and re-checks closing time, caps and "already backed" inside the transaction. Lock order is market → account; keep it.
- 🚨 **Migrations never name indexes or foreign keys**: Laravel's generated names carry the table prefix; hand-written ones don't, and MySQL FK names are database-wide (error 1826 when two forums share a database). `MigrationTest` checks every name.
- **Settling** (`Settler`) runs only from the tick or a moderator's call-off, locks the market and checks `settled_at` inside the transaction. Entry and exit prices are recomputed there with the final backer set. `Scores::rebuild` recounts season rows from positions after the commit, under the members' `hype_accounts` row locks (id order); never increment.
- A position belongs to the season it was opened in.
- Markets keep a title snapshot and `discussion_id` goes null on delete, so old leaderboards stay; settle voids a market whose discussion is gone, hidden or private. Lists filter by `Market::visibleTo` (discussion visibility); a portfolio shows positions on unreadable discussions without the title.
- The `hype` discussion attribute is a deferred batch (`Api\DiscussionHype`, static WeakMap keyed by request): one query per request, none for discussions older than window + 48h.
- Titles shown anywhere come from the discussion relation (renames follow); `hype_markets.title` only stands in once the discussion is gone.
- flarum/gdpr (optional, `Extend\Conditional`): `Gdpr\HypeData` exports calls (titles only where still visible), season totals and spending; erasure deletes them and unlinks the member's markets.
- Notification subject = `Market`, which has the `hype-markets` resource and store model: without either, notification lists break.
- Frontend: forum.js only wires things up; everything else is ONE lazy chunk (`components/index.ts`). The market bar is its own Mithril root (`m.mount`) because the post's subtree retainer never redraws children.
- Mithril has no function components: every component is a class.

## Testing

- `composer test:setup && composer test` (integration; `DB_DRIVER=mysql` + a `DB_PREFIX` to test prefixes), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum: ~/Herd/hypedev (https://hypedev.test, prefix `hy_`), seeded by the showcase seed `seeds/99-hype-market.php`; screenshots: ~/github/scopes/hype-market-shots.
