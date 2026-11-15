<?php

namespace ErnestDefoe\HypeMarket\Tests\integration\api;

use ErnestDefoe\HypeMarket\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * extend.php writes the setting defaults as string literals (it must not load our classes while a
 * deploy swaps vendor/): they have to stay the same as Settings::DEFAULTS, which the code reads.
 */
class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-hype-market');
    }

    #[Test]
    public function the_defaults_in_extend_php_match_the_ones_the_code_uses(): void
    {
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        foreach (Settings::DEFAULTS as $key => $default) {
            $this->assertEquals($default, $settings->get($key), $key);
        }
        $this->assertEquals(0, $settings->get(Settings::LOSS));
    }
}
