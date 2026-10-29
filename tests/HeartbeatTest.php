<?php

namespace LaravelOpenPackages\Heartbeat\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LaravelOpenPackages\Heartbeat\Facades\Heartbeat;
use LaravelOpenPackages\Heartbeat\Heartbeat as HeartbeatManager;

class HeartbeatTest extends TestCase
{
    public function test_a_topic_touched_after_since_has_changed(): void
    {
        $since = Heartbeat::now();
        Heartbeat::touch('counts');

        $this->assertSame(['counts'], Heartbeat::changedSince($since, ['counts', 'invoices']));
        $this->assertSame([], Heartbeat::changedSince(Heartbeat::now() + 1, ['counts']));
    }

    public function test_never_touched_topics_have_not_changed(): void
    {
        $this->assertSame([], Heartbeat::changedSince(0, ['counts']));
    }

    public function test_a_touch_in_the_same_millisecond_as_the_answer_is_not_missed(): void
    {
        $now = Heartbeat::now();
        Heartbeat::touch('counts');   // stamped >= $now

        $this->assertContains('counts', Heartbeat::changedSince($now, ['counts']));
    }

    public function test_a_touch_for_one_user_is_only_seen_by_that_user(): void
    {
        $since = Heartbeat::now();
        Heartbeat::touch('reminders', $this->user(1));

        $this->assertSame(['reminders'], Heartbeat::changedSince($since, ['reminders'], $this->user(1)));
        $this->assertSame([], Heartbeat::changedSince($since, ['reminders'], $this->user(2)));
        $this->assertSame([], Heartbeat::changedSince($since, ['reminders']));

        // A user id works as well as the user.
        Heartbeat::touch('todos', 2);
        $this->assertSame(['todos'], Heartbeat::changedSince($since, ['todos'], $this->user(2)));
    }

    public function test_a_global_touch_is_seen_by_every_user(): void
    {
        $since = Heartbeat::now();
        Heartbeat::touch(['counts', 'invoices']);

        $this->assertSame(['counts', 'invoices'], Heartbeat::changedSince($since, ['counts', 'invoices'], $this->user(7)));
    }

    public function test_a_watcher_answers_for_topics_that_change_with_time(): void
    {
        $seen = null;
        Heartbeat::watch('reminders', function ($user, CarbonImmutable $since, CarbonImmutable $now) use (&$seen) {
            $seen = [$user?->getAuthIdentifier(), $since, $now];

            return $user?->getAuthIdentifier() === 1;
        });

        $since = Heartbeat::now() - 1000;
        $this->assertSame(['reminders'], Heartbeat::changedSince($since, ['reminders'], $this->user(1)));
        $this->assertSame([], Heartbeat::changedSince($since, ['reminders'], $this->user(2)));

        $this->assertSame(2, $seen[0]);
        $this->assertSame($since, $seen[1]->getTimestampMs());
        $this->assertTrue($seen[2]->greaterThan($seen[1]));
        $this->assertSame(['reminders'], Heartbeat::watched());
    }

    public function test_a_touch_answers_before_the_watcher_is_asked(): void
    {
        $asked = false;
        Heartbeat::watch('reminders', function () use (&$asked) {
            $asked = true;

            return false;
        });
        $since = Heartbeat::now();
        Heartbeat::touch('reminders');

        $this->assertSame(['reminders'], Heartbeat::changedSince($since, ['reminders']));
        $this->assertFalse($asked);
    }

    public function test_topic_names_are_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Heartbeat::touch('not a topic!');
    }

    public function test_marks_live_in_the_configured_store_and_prefix(): void
    {
        config(['heartbeat.cache.prefix' => 'beats']);
        $this->app->forgetInstance(HeartbeatManager::class);
        Heartbeat::clearResolvedInstances();

        Heartbeat::touch('counts');

        $this->assertIsInt(cache()->get('beats:counts'));
    }

    public function test_the_interval_comes_from_config(): void
    {
        config(['heartbeat.interval' => 30]);
        $this->app->forgetInstance(HeartbeatManager::class);
        Heartbeat::clearResolvedInstances();

        $this->assertSame(30, Heartbeat::interval());
    }
}
