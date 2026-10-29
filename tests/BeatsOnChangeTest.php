<?php

namespace LaravelOpenPackages\Heartbeat\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelOpenPackages\Heartbeat\Concerns\BeatsOnChange;
use LaravelOpenPackages\Heartbeat\Facades\Heartbeat;

class BeatsOnChangeTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_saving_deleting_and_restoring_touch_the_models_topics(): void
    {
        $since = Heartbeat::now();
        $line = InvoiceLine::create(['name' => 'A']);
        $this->assertSame(['invoices', 'counts'], Heartbeat::changedSince($since, ['invoices', 'counts']));

        foreach ([fn () => $line->update(['name' => 'B']), fn () => $line->delete(), fn () => $line->restore()] as $change) {
            $since = Heartbeat::now() + 1;
            usleep(2000);
            $change();
            $this->assertSame(['invoices', 'counts'], Heartbeat::changedSince($since, ['invoices', 'counts']), 'every change touches');
        }
    }

    public function test_without_topics_the_topic_is_the_models_plural_name(): void
    {
        $since = Heartbeat::now();
        PlainLine::create(['name' => 'A']);

        $this->assertSame(['plain-lines'], Heartbeat::changedSince($since, ['plain-lines']));
    }

    public function test_a_model_can_touch_its_topics_for_particular_users_only(): void
    {
        $since = Heartbeat::now();
        OwnedLine::create(['name' => 'A', 'owner_id' => 5]);

        $this->assertSame(['my-lines'], Heartbeat::changedSince($since, ['my-lines'], $this->user(5)));
        $this->assertSame([], Heartbeat::changedSince($since, ['my-lines'], $this->user(6)));
    }
}

class InvoiceLine extends Model
{
    use BeatsOnChange;
    use SoftDeletes;

    protected $table = 'invoice_lines';

    protected $guarded = [];

    protected array $heartbeatTopics = ['invoices', 'counts'];
}

class PlainLine extends Model
{
    use BeatsOnChange;

    protected $table = 'invoice_lines';

    protected $guarded = [];
}

class OwnedLine extends Model
{
    use BeatsOnChange;

    protected $table = 'invoice_lines';

    protected $guarded = [];

    protected array $heartbeatTopics = ['my-lines'];

    public function heartbeatFor(): ?iterable
    {
        return [$this->owner_id];
    }
}
