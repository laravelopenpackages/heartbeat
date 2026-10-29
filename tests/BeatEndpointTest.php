<?php

namespace LaravelOpenPackages\Heartbeat\Tests;

use LaravelOpenPackages\Heartbeat\Facades\Heartbeat;

class BeatEndpointTest extends TestCase
{
    public function test_guests_cannot_beat(): void
    {
        $this->getJson(route('heartbeat'))->assertUnauthorized();
    }

    public function test_the_first_beat_only_answers_now_and_the_interval(): void
    {
        Heartbeat::touch('counts');

        $answer = $this->actingAs($this->user(1))->getJson(route('heartbeat', ['topics' => 'counts']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('changed', [])
            ->assertJsonPath('interval', 15);

        $this->assertIsInt($answer->json('now'));
    }

    public function test_a_beat_lists_the_topics_changed_since_the_last_one(): void
    {
        $this->actingAs($this->user(1));
        $now = $this->getJson(route('heartbeat', ['topics' => 'counts,invoices']))->json('now');

        Heartbeat::touch('counts');
        Heartbeat::touch('reminders', 1);
        Heartbeat::touch('todos', 2);   // someone else's

        $answer = $this->getJson(route('heartbeat', ['since' => $now, 'topics' => 'counts,invoices,reminders,todos']))->assertOk();
        $this->assertSame(['counts', 'reminders'], $answer->json('changed'));

        // The next beat starts from this one's `now`: nothing new since.
        $this->getJson(route('heartbeat', ['since' => $answer->json('now') + 1, 'topics' => 'counts,reminders']))
            ->assertJsonPath('changed', []);
    }

    public function test_topics_can_be_sent_as_an_array(): void
    {
        $this->actingAs($this->user(1));
        $since = Heartbeat::now();
        Heartbeat::touch('invoices');

        $this->getJson(route('heartbeat', ['since' => $since, 'topics' => ['counts', 'invoices']]))
            ->assertJsonPath('changed', ['invoices']);
    }

    public function test_bad_input_is_refused(): void
    {
        config(['heartbeat.max_topics' => 2]);
        $this->actingAs($this->user(1));

        $this->getJson(route('heartbeat', ['since' => 'yesterday']))->assertUnprocessable()->assertJsonValidationErrors('since');
        $this->getJson(route('heartbeat', ['topics' => 'a,b,c']))->assertUnprocessable()->assertJsonValidationErrors('topics');
        $this->getJson(route('heartbeat', ['topics' => 'ok,not ok!']))->assertUnprocessable()->assertJsonValidationErrors('topics.1');
    }
}
