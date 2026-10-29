<?php

namespace LaravelOpenPackages\Heartbeat\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LaravelOpenPackages\Heartbeat\Heartbeat;

/**
 * Touch topics whenever the model is created, updated, deleted or restored:
 *
 *   class Invoice extends Model
 *   {
 *       use BeatsOnChange;
 *
 *       protected array $heartbeatTopics = ['invoices', 'counts'];
 *   }
 *
 * Without `$heartbeatTopics` the topic is the model's name in kebab-case plural
 * (Invoice → "invoices"). Override heartbeatTopics() for topics that depend on the
 * record ("invoice:{id}"), and heartbeatFor() to touch them for particular users
 * only (e.g. the todo's assignee) instead of everyone.
 *
 * Model events don't fire for query-builder writes (update()/delete() on a query,
 * insert(), DB::table()) — call Heartbeat::touch() yourself there.
 */
trait BeatsOnChange
{
    public static function bootBeatsOnChange(): void
    {
        $beat = static function (Model $model): void {
            /** @var Model&BeatsOnChange $model */
            $topics = $model->heartbeatTopics();
            $heartbeat = app(Heartbeat::class);
            $users = $model->heartbeatFor();

            if ($users === null) {
                $heartbeat->touch($topics);

                return;
            }
            foreach ($users as $user) {
                $heartbeat->touch($topics, $user);
            }
        };

        static::saved($beat);
        static::deleted($beat);
        if (method_exists(static::class, 'restored')) {
            static::restored($beat);
        }
    }

    /** @return list<string> */
    public function heartbeatTopics(): array
    {
        if (property_exists($this, 'heartbeatTopics')) {
            return (array) $this->heartbeatTopics;
        }

        return [Str::plural(Str::kebab(class_basename($this)))];
    }

    /**
     * Who the touch is for: null = everyone (default), or the users / user ids to touch
     * it for (an empty list touches no one).
     *
     * @return iterable<\Illuminate\Contracts\Auth\Authenticatable|int|string>|null
     */
    public function heartbeatFor(): ?iterable
    {
        return null;
    }
}
