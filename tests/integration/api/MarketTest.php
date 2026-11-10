<?php

namespace ErnestDefoe\HypeMarket\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\HypeMarket\Account;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Markets;
use ErnestDefoe\HypeMarket\Position;
use ErnestDefoe\HypeMarket\Pricing;
use ErnestDefoe\HypeMarket\Repricer;
use ErnestDefoe\HypeMarket\Score;
use ErnestDefoe\HypeMarket\Season;
use ErnestDefoe\HypeMarket\Seasons;
use ErnestDefoe\HypeMarket\Settler;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every farming route we could think of, each proved closed, plus the
 * settlement guarantees (idempotent, late = on time, caps under the lock).
 *
 * The clock is frozen at NOW. Discussion 1 (by ava, id 3) started an hour
 * earlier; its market closes at +24h and settles at +72h.
 */
class MarketTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const NOW = '2026-11-10 12:00:00';

    private const AVA = 3;      // the author
    private const BO = 4;       // a backer
    private const CY = 5;       // another backer
    private const DEE = 6;      // trusted repliers
    private const ELI = 7;
    private const FIN = 8;      // joined yesterday
    private const GUS = 9;      // no posts yet
    private const HAL = 10;     // unconfirmed email
    private const MO = 11;      // moderator
    private const IVY = 12;     // more trusted repliers
    private const JAY = 13;

    private int $postId = 100;

    /** @var array<int, int> */
    private array $numbers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->extension('flarum-likes', 'flarum-gdpr', 'ernestdefoe-hype-market');

        $trusted = fn (int $id, string $name, array $extra = []) => $extra + [
            'id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1,
            'joined_at' => Carbon::now()->subDays(30), 'comment_count' => 10,
        ];

        $users = [
            $this->normalUser(),
            $trusted(self::AVA, 'ava'),
            $trusted(self::BO, 'bo'),
            $trusted(self::CY, 'cy'),
            $trusted(self::DEE, 'dee'),
            $trusted(self::ELI, 'eli'),
            $trusted(self::FIN, 'fin', ['joined_at' => Carbon::now()->subDay()]),
            $trusted(self::GUS, 'gus', ['comment_count' => 0]),
            $trusted(self::HAL, 'hal', ['is_email_confirmed' => 0]),
            $trusted(self::MO, 'mo'),
            $trusted(self::IVY, 'ivy'),
            $trusted(self::JAY, 'jay'),
        ];

        $groups = [];
        foreach ($users as $user) {
            if ($user['id'] > 2) {
                $groups[] = ['user_id' => $user['id'], 'group_id' => $user['id'] === self::MO ? Group::MODERATOR_ID : Group::MEMBER_ID];
            }
        }

        $this->prepareDatabase([
            User::class => $users,
            'group_user' => $groups,
            Discussion::class => [
                $this->discussionRow(1, self::AVA, 1),
                // Too old to back.
                $this->discussionRow(2, self::AVA, 30),
                // Private (e.g. a byobu conversation).
                $this->discussionRow(3, self::AVA, 1, ['is_private' => 1]),
                // bo's own.
                $this->discussionRow(4, self::BO, 1),
            ],
            Post::class => [
                $this->postRow(1, 1, self::AVA, 1, Carbon::now()->subHour()),
                $this->postRow(2, 2, self::AVA, 1, Carbon::now()->subHours(30)),
                $this->postRow(3, 3, self::AVA, 1, Carbon::now()->subHour()),
                $this->postRow(4, 4, self::BO, 1, Carbon::now()->subHour()),
            ],
        ]);
        $this->numbers = [1 => 1, 2 => 1, 3 => 1, 4 => 1];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function discussionRow(int $id, int $userId, int $hoursAgo, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'title' => "Discussion $id", 'slug' => "discussion-$id", 'user_id' => $userId, 'created_at' => Carbon::now()->subHours($hoursAgo),
            'first_post_id' => $id, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => 0,
        ];
    }

    private function postRow(int $id, int $discussionId, int $userId, int $number, Carbon $at, string $text = 'Kicking this off.'): array
    {
        return ['id' => $id, 'discussion_id' => $discussionId, 'user_id' => $userId, 'type' => 'comment', 'number' => $number,
            'content' => '<t><p>'.htmlspecialchars($text).'</p></t>', 'created_at' => $at, 'is_private' => 0];
    }

    /** More of ava's discussions, an hour old (first posts after the discussions, for MySQL's foreign keys). */
    private function discussions(array $ids): void
    {
        $this->database()->table('discussions')->insert(array_map(fn ($id) => ['first_post_id' => null] + $this->discussionRow($id, self::AVA, 1), $ids));
        $this->database()->table('posts')->insert(array_map(fn ($id) => $this->postRow($id, $id, self::AVA, 1, Carbon::now()->subHour()), $ids));
    }

    /** A reply $minutes after NOW (negative = before). Returns the post id. */
    private function reply(int $userId, int $minutes, string $text = 'This is a real reply with something to say.', int $discussionId = 1, array $extra = []): int
    {
        $id = ++$this->postId;
        $number = $this->numbers[$discussionId] = ($this->numbers[$discussionId] ?? 1) + 1;
        $this->database()->table('posts')->insert($extra + $this->postRow($id, $discussionId, $userId, $number, Carbon::now()->addMinutes($minutes), $text));

        return $id;
    }

    private function like(int $postId, int $userId, int $minutes): void
    {
        $this->database()->table('post_likes')->insert(['post_id' => $postId, 'user_id' => $userId, 'created_at' => Carbon::now()->addMinutes($minutes)]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $response = $this->send($this->request($method, '/api/hype'.$path, $options)->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return array{int, array<string, mixed>|null} */
    private function back(int $as, int $stake = 20, int $discussionId = 1): array
    {
        return $this->api('POST', "/discussions/$discussionId/back", $as, ['stake' => $stake]);
    }

    private function backOk(int $as, int $stake = 20, int $discussionId = 1): void
    {
        [$status, $body] = $this->back($as, $stake, $discussionId);
        $this->assertSame(201, $status, json_encode($body));
    }

    private function error(array $body): ?string
    {
        return $body['errors'][0]['code'] ?? null;
    }

    private function market(int $discussionId = 1): Market
    {
        return Market::query()->where('discussion_id', $discussionId)->firstOrFail();
    }

    private function reprice(int $discussionId = 1): Market
    {
        $this->app()->getContainer()->make(Markets::class)->openNew();
        // Far enough on for the replies the tests place after NOW, still inside the window.
        $now = Carbon::now()->max(Carbon::parse(self::NOW)->addHours(6));
        $this->app()->getContainer()->make(Repricer::class)->reprice(Market::query()->where('discussion_id', $discussionId)->get(), $now);

        return $this->market($discussionId);
    }

    /** Jump to after the settle time and settle. */
    private function settle(int $discussionId = 1, int $hoursAfterNow = 72): bool
    {
        Carbon::setTestNow(Carbon::parse(self::NOW)->addHours($hoursAfterNow));

        return $this->app()->getContainer()->make(Settler::class)->settle($this->market($discussionId)->id);
    }

    private function position(int $userId, int $discussionId = 1): Position
    {
        return Position::query()->where('market_id', $this->market($discussionId)->id)->where('user_id', $userId)->firstOrFail();
    }

    // ---------------------------------------------------------------- pricing

    #[Test]
    public function the_price_rises_with_real_engagement_and_early_backers_earn_most(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addMinutes(30));
        $this->backOk(self::CY, 50);
        $this->reply(self::IVY, 60);
        $this->reply(self::JAY, 90);

        $this->assertTrue($this->settle());

        $bo = $this->position(self::BO);
        $cy = $this->position(self::CY);
        $this->assertSame(10.0, $bo->entry_price);
        $this->assertGreaterThan($bo->entry_price, $cy->entry_price);
        $this->assertGreaterThan(0, $bo->profit);
        $this->assertGreaterThan($cy->profit, $bo->profit, 'the earlier call pays more');
        $this->assertSame($bo->payout, Pricing::payout(50, $bo->multiplier));
    }

    #[Test]
    public function the_authors_own_replies_never_move_the_price(): void
    {
        $this->backOk(self::BO);
        for ($i = 1; $i <= 10; $i++) {
            $this->reply(self::AVA, $i);
        }
        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function a_backers_own_replies_and_likes_never_move_the_price(): void
    {
        $this->backOk(self::BO);
        $post = $this->reply(self::BO, 5);
        $this->reply(self::BO, 6);
        $this->like(1, self::BO, 7);
        $this->assertSame(10.0, $this->reprice()->price);

        // A fellow backer's replies don't lift bo either: they count for nobody.
        $this->backOk(self::CY);
        $this->reply(self::CY, 8);
        $this->like($post, self::CY, 9);
        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function replying_first_and_backing_after_takes_your_replies_out(): void
    {
        $this->reply(self::CY, -30);
        $this->reply(self::DEE, -20);
        $before = $this->reprice()->price;
        $this->backOk(self::CY);
        $after = $this->reprice()->price;

        $this->assertLessThan($before, $after, "cy's earlier reply left the price when cy backed");
        $this->assertSame(Pricing::at([['t' => 0, 'u' => self::DEE, 'k' => 'r']], 1, [])['price'], $after);
    }

    #[Test]
    public function new_postless_and_unconfirmed_accounts_dont_count(): void
    {
        $this->backOk(self::BO);
        $this->reply(self::FIN, 5);
        $this->reply(self::GUS, 6);
        $this->reply(self::HAL, 7);
        $this->like(1, self::FIN, 8);
        $this->like(1, self::GUS, 8);
        $this->like(1, self::HAL, 8);

        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function an_account_that_ages_into_trust_doesnt_bring_its_old_replies(): void
    {
        $this->backOk(self::BO);
        $this->reply(self::FIN, 5);
        // Seven days after joining fin is trusted, but the reply was made while new.
        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays(7));
        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function one_word_replies_and_quote_only_replies_dont_count(): void
    {
        $this->backOk(self::BO);
        $this->reply(self::DEE, 5, '+1');
        $this->reply(self::ELI, 6, 'this!!');
        $this->database()->table('posts')->insert($this->postRow(++$this->postId, 1, self::IVY, ++$this->numbers[1], Carbon::now()->addMinutes(7), '')
            + []);
        $this->database()->table('posts')->where('id', $this->postId)->update(['content' => '<r><QUOTE><i>&gt; </i><p>Kicking this off with a long quoted paragraph.</p></QUOTE><p>ok</p></r>']);

        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function one_member_spamming_replies_is_capped(): void
    {
        $this->backOk(self::BO);
        for ($i = 1; $i <= 3; $i++) {
            $this->reply(self::DEE, $i);
        }
        $three = $this->reprice()->price;
        for ($i = 4; $i <= 30; $i++) {
            $this->reply(self::DEE, $i);
        }
        // Real posting raises comment_count with every reply (these raw inserts don't): 10 elsewhere + 30 here.
        $this->database()->table('users')->where('id', self::DEE)->update(['comment_count' => 40]);
        $this->assertSame($three, $this->reprice()->price, 'replies past the third add nothing');
        $this->assertSame(Pricing::price(3 + 1 + 0.5 + 0.25), $three);
    }

    #[Test]
    public function likes_are_capped_per_member_and_never_count_on_your_own_post(): void
    {
        $this->backOk(self::BO);
        $posts = [];
        for ($i = 1; $i <= 5; $i++) {
            $posts[] = $this->reply(self::ELI, -60 + $i, 'Reply number '.$i.' with enough text.', 1, []);
        }
        $base = $this->reprice()->price;
        foreach ($posts as $i => $post) {
            $this->like($post, self::DEE, 10 + $i);
            // Liking your own post: never counted (and core doesn't allow it either).
            $this->like($post, self::ELI, 10 + $i);
        }
        $this->assertSame(
            Pricing::price(3 + 1 + 0.5 + 0.25 + 3 * Pricing::LIKE),
            $this->reprice()->price
        );
        $this->assertGreaterThan($base, $this->reprice()->price);
    }

    #[Test]
    public function hidden_and_private_replies_dont_count(): void
    {
        $this->backOk(self::BO);
        $this->reply(self::DEE, 5, 'A reply a moderator hid as spam later on.', 1, ['hidden_at' => Carbon::now()]);
        $this->reply(self::ELI, 6, 'A private reply nobody else can read here.', 1, ['is_private' => 1]);

        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function engagement_after_the_window_doesnt_count_even_when_the_tick_runs_late(): void
    {
        $this->backOk(self::BO);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);
        // Settle time is NOW + 71h (the discussion started an hour before NOW).
        $this->reply(self::IVY, 72 * 60);
        $this->reply(self::JAY, 72 * 60 + 5);

        $this->assertTrue($this->settle(1, 100));
        $expected = Pricing::price(2 * (3 + 1));
        $this->assertSame($expected, $this->market()->final_price);
    }

    // ---------------------------------------------------------------- backing rules

    #[Test]
    public function you_cant_back_your_own_discussion(): void
    {
        [$status, $body] = $this->back(self::BO, 20, 4);
        $this->assertSame(422, $status);
        $this->assertSame('hype_own_discussion', $this->error($body));
        $this->assertSame(0, Position::query()->count());
    }

    #[Test]
    public function old_private_and_unseen_discussions_cant_be_backed(): void
    {
        [$status, $body] = $this->back(self::BO, 20, 2);
        $this->assertSame('hype_not_backable', $this->error($body));

        [$status, $body] = $this->back(self::BO, 20, 3);
        $this->assertContains($status, [404, 422]);

        [$status] = $this->back(self::BO, 20, 999);
        $this->assertSame(404, $status);
        $this->assertSame(0, Position::query()->count());
    }

    #[Test]
    public function backing_closes_on_time_even_with_a_stale_page(): void
    {
        $this->backOk(self::BO);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addHours(23));
        [$status, $body] = $this->back(self::CY);
        $this->assertSame('hype_closed', $this->error($body));
    }

    #[Test]
    public function guests_and_members_without_permission_cant_back(): void
    {
        [$status] = $this->back(0);
        $this->assertContains($status, [400, 401, 403]);
        $this->assertSame(0, Position::query()->count());

        $this->database()->table('group_permission')->where('permission', 'hype.back')->delete();
        [$status] = $this->back(self::BO);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function stakes_must_be_whole_numbers_inside_the_limits(): void
    {
        foreach ([0, 4, 51, -10, '12.5', 'lots', 1000000] as $stake) {
            [$status, $body] = $this->api('POST', '/discussions/1/back', self::BO, ['stake' => $stake]);
            $this->assertSame(422, $status, (string) $stake);
            $this->assertSame('hype_bad_stake', $this->error($body));
        }
    }

    #[Test]
    public function one_position_per_member_per_discussion(): void
    {
        $this->backOk(self::BO);
        [$status, $body] = $this->back(self::BO);
        $this->assertSame('hype_already_backed', $this->error($body));

        // And the database refuses a second row even if a check were skipped.
        $this->expectException(\Illuminate\Database\QueryException::class);
        Position::query()->insert(['market_id' => $this->market()->id, 'user_id' => self::BO, 'stake' => 5, 'entry_price' => 10, 'created_at' => Carbon::now()]);
    }

    #[Test]
    public function the_daily_allowance_and_back_count_are_capped_and_reset_next_day(): void
    {
        $this->discussions(range(10, 16));

        $this->backOk(self::BO, 50, 10);
        $this->backOk(self::BO, 40, 11);
        [, $body] = $this->back(self::BO, 20, 12);
        $this->assertSame('hype_allowance', $this->error($body), '50 + 40 + 20 > 100');
        $this->backOk(self::BO, 10, 12);
        $this->assertSame(100, Account::today(self::BO)['spent']);

        // Five backs a day, whatever the stakes.
        $this->backOk(self::CY, 5, 10);
        $this->backOk(self::CY, 5, 11);
        $this->backOk(self::CY, 5, 12);
        $this->backOk(self::CY, 5, 13);
        $this->backOk(self::CY, 5, 14);
        [, $body] = $this->back(self::CY, 5, 15);
        $this->assertSame('hype_daily_backs', $this->error($body));

        // A new UTC day: a fresh allowance.
        Carbon::setTestNow(Carbon::parse(self::NOW)->addHours(12)->addMinute());
        $this->backOk(self::BO, 50, 15);
    }

    #[Test]
    public function a_refused_back_spends_nothing(): void
    {
        $this->backOk(self::BO, 50);
        $this->back(self::BO, 50);
        $this->back(self::BO, 50, 4);
        $this->assertSame(['spent' => 50, 'backs' => 1], Account::today(self::BO));
    }

    #[Test]
    public function the_entry_price_comes_from_the_server_not_the_request(): void
    {
        $this->reply(self::DEE, -30);
        [$status] = $this->api('POST', '/discussions/1/back', self::BO, ['stake' => 20, 'entryPrice' => 0.01, 'price' => 0.01]);
        $this->assertSame(201, $status);
        $this->assertSame(Pricing::price(4), $this->position(self::BO)->entry_price);
    }

    // ---------------------------------------------------------------- settlement

    #[Test]
    public function settling_twice_changes_nothing_and_totals_are_recounted_not_added(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);

        $this->assertTrue($this->settle());
        $first = $this->position(self::BO)->toArray();
        $score = Score::query()->where('user_id', self::BO)->firstOrFail()->toArray();

        $this->assertFalse($this->settle(1, 80));
        $this->assertSame($first, $this->position(self::BO)->toArray());
        $this->assertSame($score, Score::query()->where('user_id', self::BO)->firstOrFail()->toArray());

        // Even a forced rebuild of the totals lands on the same numbers.
        $this->app()->getContainer()->make(\ErnestDefoe\HypeMarket\Scores::class)->rebuild([[(int) $first['season_id'], self::BO]]);
        $this->assertSame($score, Score::query()->where('user_id', self::BO)->firstOrFail()->toArray());
        $this->assertSame(1, $score['settled']);
        $this->assertSame($first['profit'], $score['profit']);
    }

    #[Test]
    public function nothing_settles_before_its_time(): void
    {
        $this->backOk(self::BO);
        $this->assertFalse($this->settle(1, 70));
        $this->assertNull($this->market()->settled_at);
    }

    #[Test]
    public function one_friend_alone_cant_make_a_call_pay(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::DEE, 10);
        $this->reply(self::DEE, 11);
        $this->reply(self::DEE, 12);
        foreach ([1, $this->reply(self::AVA, 1), $this->reply(self::AVA, 2)] as $i => $post) {
            $this->like($post, self::DEE, 13 + $i);
        }

        $this->assertTrue($this->settle());
        $bo = $this->position(self::BO);
        $this->assertSame(1.0, $bo->multiplier, 'one participant is below the minimum of two');
        $this->assertSame(0, $bo->profit);
        $this->assertSame(50, $bo->payout);
    }

    #[Test]
    public function the_same_friends_lift_a_backers_calls_only_so_often_a_season(): void
    {
        $this->discussions(range(20, 20 + Settler::PAIR_CAP));
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $settings->set('ernestdefoe-hype-market.max_backs', '20');
        $settings->set('ernestdefoe-hype-market.daily_allowance', '1000');

        $profits = [];
        for ($id = 20; $id < 20 + Settler::PAIR_CAP + 1; $id++) {
            Carbon::setTestNow(Carbon::parse(self::NOW));
            $this->backOk(self::BO, 10, $id);
            $this->reply(self::DEE, 10, 'Dee always shows up for bo, every time.', $id);
            $this->reply(self::ELI, 11, 'And so does Eli, on every single one.', $id);
            $this->assertTrue($this->settle($id));
            $profits[] = $this->position(self::BO, $id)->profit;
        }

        $this->assertGreaterThan(0, $profits[0]);
        $this->assertSame(array_fill(0, Settler::PAIR_CAP, $profits[0]), array_slice($profits, 0, Settler::PAIR_CAP));
        $this->assertSame(0, end($profits), 'after PAIR_CAP paid calls, dee and eli no longer count for bo');
    }

    #[Test]
    public function a_discussion_hidden_or_deleted_before_settling_is_called_off(): void
    {
        $this->backOk(self::BO, 30);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);
        $this->database()->table('discussions')->where('id', 1)->update(['hidden_at' => Carbon::now()]);

        $this->assertTrue($this->settle());
        $this->assertNotNull($this->market()->voided_at);
        $this->assertSame(0, $this->position(self::BO)->profit);
        $this->assertSame(30, $this->position(self::BO)->payout);
    }

    #[Test]
    public function a_moderator_can_call_off_a_gamed_market_and_a_member_cant(): void
    {
        $this->backOk(self::BO, 30);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);
        $id = $this->market()->id;

        [$status] = $this->api('POST', "/markets/$id/void", self::CY);
        $this->assertSame(403, $status);

        [$status] = $this->api('POST', "/markets/$id/void", self::MO);
        $this->assertSame(200, $status);
        $this->assertSame(0, $this->position(self::BO)->profit);

        // Settled is settled: the tick can't pay it out afterwards, and a second void is refused.
        $this->assertFalse($this->settle());
        [$status, $body] = $this->api('POST', "/markets/$id/void", self::MO);
        $this->assertSame('hype_already_settled', $this->error($body));
    }

    #[Test]
    public function a_later_backers_earlier_replies_dont_shift_an_earlier_backers_numbers(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::CY, 10);
        $this->reply(self::DEE, 20);
        $this->reply(self::ELI, 30);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addHour());
        $this->backOk(self::CY, 50);

        $this->assertTrue($this->settle());
        $bo = $this->position(self::BO);
        $this->assertSame(10.0, $bo->entry_price);
        // cy's reply is out of both ends of bo's call: only dee and eli count.
        $this->assertSame(Pricing::price(2 * 4), $bo->exit_price);
    }

    #[Test]
    public function replies_in_the_thread_itself_dont_make_an_account_established(): void
    {
        // gus had no posts; three replies here bring comment_count to 3, all of them in this thread.
        $this->database()->table('users')->where('id', self::GUS)->update(['comment_count' => 3]);
        $this->backOk(self::BO);
        foreach ([5, 6, 7] as $minute) {
            $this->reply(self::GUS, $minute);
        }
        $this->like(1, self::GUS, 8);
        $this->assertSame(10.0, $this->reprice()->price);

        // Three comments elsewhere and gus counts.
        $this->database()->table('users')->where('id', self::GUS)->update(['comment_count' => 6]);
        $this->assertGreaterThan(10.0, $this->reprice()->price);
    }

    #[Test]
    public function posts_dated_before_the_market_opened_dont_count(): void
    {
        $this->backOk(self::BO);
        // Merged in from an older discussion: dated before this one started.
        $old = $this->reply(self::DEE, -180);
        $this->reply(self::ELI, -170);
        $this->like($old, self::IVY, -160);
        $this->assertSame(10.0, $this->reprice()->price);
    }

    #[Test]
    public function every_settled_call_lands_in_the_season_total(): void
    {
        $this->discussions([30]);
        $this->backOk(self::BO, 50);
        $this->backOk(self::BO, 30, 30);
        foreach ([1, 30] as $id) {
            $this->reply(self::DEE, 10, 'Dee has thoughts on this one as well.', $id);
            $this->reply(self::ELI, 11, 'Eli has thoughts on this one as well.', $id);
        }
        $this->assertTrue($this->settle(1));
        $this->assertTrue($this->settle(30));

        $score = Score::query()->where('user_id', self::BO)->firstOrFail();
        $this->assertSame(2, $score->settled);
        $this->assertSame((int) Position::query()->where('user_id', self::BO)->sum('profit'), $score->profit);
        $this->assertSame(1, Account::query()->whereKey(self::BO)->count(), 'the rebuild locks the account row it made or found');
    }

    #[Test]
    public function alerts_show_the_discussions_title_as_it_is_now(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 11);
        $this->database()->table('discussions')->where('id', 1)->update(['title' => 'Renamed after it opened']);
        $this->assertTrue($this->settle());

        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => self::BO]));
        $this->assertSame(200, $response->getStatusCode());
        $titles = array_map(fn ($i) => $i['attributes']['title'] ?? null, array_filter(json_decode((string) $response->getBody(), true)['included'] ?? [], fn ($i) => $i['type'] === 'hype-markets'));
        $this->assertSame(['Renamed after it opened'], array_values($titles));
    }

    #[Test]
    public function a_members_data_export_has_their_calls_and_erasing_removes_them(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 11);
        $this->assertTrue($this->settle());

        $user = User::query()->findOrFail(self::BO);
        $data = $this->app()->getContainer()->make(\ErnestDefoe\HypeMarket\Gdpr\HypeData::class, ['user' => $user, 'erasureRequest' => null]);
        $export = json_decode(array_values($data->export()[0])[0], true);
        $this->assertSame('Discussion 1', $export['calls'][0]['discussion']);
        $this->assertSame(50, $export['calls'][0]['stake']);
        $this->assertCount(1, $export['seasons']);

        $data->delete();
        $this->assertSame(0, Position::query()->where('user_id', self::BO)->count());
        $this->assertSame(0, Score::query()->where('user_id', self::BO)->count());
        $this->assertSame(1, Market::query()->count(), 'the market stays for anyone else in it');
    }

    private function lossRate(int $percent): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('ernestdefoe-hype-market.loss_percent', (string) $percent);
    }

    #[Test]
    public function with_losses_on_a_quiet_call_loses_its_share(): void
    {
        $this->lossRate(50);
        $this->backOk(self::BO, 40);
        // One friend alone: below the participant minimum, so it counts as flat.
        $this->reply(self::DEE, 10);

        $this->assertTrue($this->settle());
        $bo = $this->position(self::BO);
        $this->assertSame(50, $bo->loss);
        $this->assertSame(0.5, $bo->multiplier);
        $this->assertSame(20, $bo->payout);
        $this->assertSame(-20, $bo->profit);
        $this->assertSame(-20, Score::query()->where('user_id', self::BO)->value('profit'));
        $this->assertSame(0, Score::query()->where('user_id', self::BO)->value('wins'));
    }

    #[Test]
    public function with_losses_on_a_call_that_grows_enough_still_profits(): void
    {
        $this->lossRate(20);
        $this->backOk(self::BO, 50);
        foreach ([self::DEE, self::ELI, self::IVY, self::JAY] as $i => $member) {
            $this->reply($member, 10 + $i);
        }

        $this->assertTrue($this->settle());
        $bo = $this->position(self::BO);
        $growth = $bo->exit_price / $bo->entry_price;
        $this->assertEqualsWithDelta(round($growth - 0.2, 4), $bo->multiplier, 0.0001);
        $this->assertGreaterThan(0, $bo->profit);
        $this->assertSame(Pricing::payout(50, $bo->multiplier) - 50, $bo->profit);
    }

    #[Test]
    public function a_call_keeps_the_loss_rate_it_was_made_with(): void
    {
        $this->backOk(self::BO, 40);
        // Losses switched on after bo backed: bo's call still can't lose.
        $this->lossRate(100);
        $this->backOk(self::CY, 40);

        $this->assertTrue($this->settle());
        $this->assertSame(40, $this->position(self::BO)->payout);
        $this->assertSame(0, $this->position(self::CY)->payout, 'cy backed at 100%: a flat call loses it all');
    }

    #[Test]
    public function a_called_off_market_refunds_every_stake_even_with_losses_on(): void
    {
        $this->lossRate(100);
        $this->backOk(self::BO, 30);
        $this->database()->table('discussions')->where('id', 1)->update(['hidden_at' => Carbon::now()]);

        $this->assertTrue($this->settle());
        $this->assertSame(30, $this->position(self::BO)->payout);
        $this->assertSame(0, $this->position(self::BO)->profit);
    }

    #[Test]
    public function the_leaderboard_ranks_losses_below_zero(): void
    {
        $this->lossRate(50);
        $this->backOk(self::BO, 40);
        $this->assertTrue($this->settle());

        [, $body] = $this->api('GET', '/leaderboard', self::BO);
        $this->assertSame(-20, $body['rows'][0]['profit']);
        $this->assertSame(-20, $body['me']['profit']);
    }

    #[Test]
    public function payouts_are_capped(): void
    {
        $this->assertSame(Pricing::MAX_MULTIPLIER, Pricing::multiplier(10, 1000, 50, 2));
        $this->assertSame(1.0, Pricing::multiplier(20, 10, 50, 2), 'by default no member loses hype');
        $this->assertSame(0.75, Pricing::multiplier(20, 10, 50, 2, 25));
        $this->assertSame(0.0, Pricing::multiplier(20, 10, 50, 2, 100));
        $this->assertSame(Pricing::MAX_MULTIPLIER, Pricing::multiplier(10, 1000, 50, 2, 30), 'the cap applies after the loss share');
    }

    // ---------------------------------------------------------------- reading

    #[Test]
    public function lists_only_show_markets_on_discussions_you_can_see(): void
    {
        $this->backOk(self::BO);
        $this->app()->getContainer()->make(Markets::class)->openNew();
        $this->database()->table('discussions')->where('id', 1)->update(['hidden_at' => Carbon::now()]);

        [$status, $body] = $this->api('GET', '/markets?sort=new', self::CY);
        $this->assertSame(200, $status);
        $this->assertNotContains(1, array_map(fn ($m) => $m['discussion']['id'], $body['markets']));
        $this->assertNotContains(3, array_map(fn ($m) => $m['discussion']['id'], $body['markets']), 'private discussions never get a market');

        // bo still sees the position, without the title of a discussion they can no longer read.
        [$status, $body] = $this->api('GET', '/portfolio', self::BO);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['open']);
        $this->assertNull($body['open'][0]['discussion']);
    }

    #[Test]
    public function the_leaderboard_ranks_by_profit_in_a_fixed_number_of_queries(): void
    {
        $this->backOk(self::BO, 50);
        $this->backOk(self::CY, 10);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);
        $this->assertTrue($this->settle());

        [$status, $body] = $this->api('GET', '/leaderboard', self::BO);
        $this->assertSame(200, $status);
        $this->assertSame(['bo', 'cy'], array_map(fn ($r) => $r['user']['username'], $body['rows']));
        $this->assertSame(1, $body['me']['rank']);

        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });
        $this->api('GET', '/leaderboard', 0);
        $few = $queries;
        $this->assertLessThan(20, $few);
    }

    #[Test]
    public function discussion_lists_carry_the_chip_in_one_query(): void
    {
        $this->backOk(self::BO);
        $this->backOk(self::CY);

        [$status, $body] = $this->api('GET', '/discussions/1', 0);
        $this->assertSame(200, $status);
        $this->assertSame(2, $body['market']['backers']);
        $this->assertNull($body['wallet']);

        $response = $this->send($this->request('GET', '/api/discussions', ['authenticatedAs' => self::DEE]));
        $data = json_decode((string) $response->getBody(), true)['data'];
        $byId = array_column(array_map(fn ($d) => ['id' => $d['id'], 'hype' => $d['attributes']['hype'] ?? null], $data), 'hype', 'id');
        $this->assertSame(2, $byId['1']['backers']);
        $this->assertNull($byId['2'] ?? null, 'too old for a live market');
    }

    #[Test]
    public function a_new_month_starts_a_new_season_exactly_once(): void
    {
        $seasons = $this->app()->getContainer()->make(Seasons::class);
        $first = Season::current();
        Carbon::setTestNow(Carbon::parse(self::NOW)->addMonth());
        $this->assertTrue($seasons->rollMonthly());
        $this->assertFalse($seasons->rollMonthly());
        $this->assertNotNull($first->fresh()->ended_at);
        $this->assertSame(2, Season::query()->count());
    }

    #[Test]
    public function the_season_a_call_was_made_in_keeps_it(): void
    {
        $this->backOk(self::BO, 50);
        $this->reply(self::DEE, 10);
        $this->reply(self::ELI, 20);
        $old = Season::current()->id;
        $this->app()->getContainer()->make(Seasons::class)->startNew('Next');

        $this->assertTrue($this->settle());
        $this->assertSame($old, Score::query()->where('user_id', self::BO)->value('season_id'));
    }

    #[Test]
    public function only_admins_end_a_season(): void
    {
        [$status] = $this->api('POST', '/seasons', self::MO, ['name' => 'December']);
        $this->assertSame(403, $status);
        [$status] = $this->api('POST', '/seasons', 1, ['name' => 'December']);
        $this->assertSame(201, $status);
    }
}
