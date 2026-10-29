<?php

namespace LaravelOpenPackages\Heartbeat\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Validator;
use LaravelOpenPackages\Heartbeat\Heartbeat;

/**
 * GET heartbeat?since=<ms>&topics=a,b (or topics[]=a&topics[]=b) → { now, changed, interval }.
 *
 * `now` goes back as `since` on the next beat; `changed` lists the asked-for topics that
 * changed since then; `interval` is how long to wait before beating again (seconds).
 * Without `since` it's the first beat: it only answers `now`, to start from.
 */
class BeatController extends Controller
{
    public function __invoke(Request $request, Heartbeat $heartbeat, ValidationFactory $validation): JsonResponse
    {
        $topics = $this->topics($request);

        $validator = $validation->make(
            ['since' => $request->query('since'), 'topics' => $topics],
            ['since' => ['nullable', 'integer', 'min:0'], 'topics' => ['array'], 'topics.*' => ['string', 'regex:' . Heartbeat::TOPIC_PATTERN]],
            ['topics.*.regex' => 'Invalid topic: use letters, digits and - _ . : (up to 100).'],
        );
        $validator->after(function (Validator $v) use ($topics) {
            $max = (int) config('heartbeat.max_topics', 50);
            if (count($topics) > $max) {
                $v->errors()->add('topics', "Ask for at most {$max} topics per beat.");
            }
        });
        $validator->validate();

        $now = $heartbeat->now();
        $since = $request->filled('since') ? (int) $request->query('since') : null;

        return response()
            ->json([
                'now' => $now,
                'changed' => $since === null ? [] : $heartbeat->changedSince($since, $topics, $request->user(), $now),
                'interval' => $heartbeat->interval(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /** @return list<string> */
    private function topics(Request $request): array
    {
        $raw = $request->query('topics', []);
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $list), fn ($t) => $t !== '')));
    }
}
