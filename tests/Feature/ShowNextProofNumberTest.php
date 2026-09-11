<?php

namespace Tests\Feature;

use App\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Show::getNextProofNumber pops from the per-show Redis pool and refills it from
 * the originals on disk when empty. A failed/empty pop must throw instead of
 * returning false/null as if it were a valid proof number.
 */
class ShowNextProofNumberTest extends TestCase
{
    use RefreshDatabase;

    protected Show $show;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fullsize');

        Show::withoutEvents(function () {
            $this->show = Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']);
        });
    }

    public function test_returns_the_number_popped_from_the_redis_pool(): void
    {
        $client = Mockery::mock();
        $client->shouldReceive('exists')->once()->with('available_proof_numbers_SHOW1')->andReturn(1);
        $client->shouldReceive('llen')->once()->with('available_proof_numbers_SHOW1')->andReturn(2);
        $client->shouldReceive('lpop')->once()->with('available_proof_numbers_SHOW1')->andReturn('SHOW1_00123');
        $client->shouldNotReceive('rpush');

        Redis::shouldReceive('client')->once()->andReturn($client);

        $this->assertSame('SHOW1_00123', $this->show->getNextProofNumber());
    }

    public function test_empty_pool_is_refilled_from_the_originals_before_popping(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_00042.jpg', 'x');

        $pushed = [];
        $client = Mockery::mock();
        $client->shouldReceive('exists')->once()->with('available_proof_numbers_SHOW1')->andReturn(1);
        $client->shouldReceive('llen')->once()->with('available_proof_numbers_SHOW1')->andReturn(0);
        $client->shouldReceive('rpush')->andReturnUsing(function (string $key, string $value) use (&$pushed) {
            $pushed[] = [$key, $value];

            return 1;
        });
        $client->shouldReceive('lpop')->once()->with('available_proof_numbers_SHOW1')->andReturn('SHOW1_00043');

        Redis::shouldReceive('client')->once()->andReturn($client);

        $this->assertSame('SHOW1_00043', $this->show->getNextProofNumber());
        $this->assertNotEmpty($pushed, 'The empty pool should have been refilled from the originals.');
        $this->assertSame(['available_proof_numbers_SHOW1', 'SHOW1_00043'], $pushed[0]);
    }

    #[DataProvider('invalidPopValues')]
    public function test_failed_pop_throws_instead_of_returning_an_invalid_number(mixed $popValue, string $typeLabel): void
    {
        $client = Mockery::mock();
        $client->shouldReceive('exists')->once()->with('available_proof_numbers_SHOW1')->andReturn(1);
        $client->shouldReceive('llen')->once()->with('available_proof_numbers_SHOW1')->andReturn(5);
        $client->shouldReceive('lpop')->once()->with('available_proof_numbers_SHOW1')->andReturn($popValue);
        $client->shouldNotReceive('rpush');

        Redis::shouldReceive('client')->once()->andReturn($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('available_proof_numbers_SHOW1');
        $this->expectExceptionMessage($typeLabel);

        $this->show->getNextProofNumber();
    }

    public static function invalidPopValues(): array
    {
        return [
            'phpredis false (empty list)' => [false, 'false'],
            'null client result' => [null, 'null'],
            'blank string' => ['   ', 'string'],
        ];
    }
}
