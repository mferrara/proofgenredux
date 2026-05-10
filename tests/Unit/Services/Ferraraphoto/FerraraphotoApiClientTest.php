<?php

use App\Models\Show;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Ferraraphoto\FerraraphotoApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function proofgen_api_client(?string $token = 'token'): FerraraphotoApiClient
{
    return new FerraraphotoApiClient('https://ferraraphoto.test', (string) $token, Log::getLogger());
}

it('posts storage profile payloads to the ferraraphoto API', function () {
    Http::fake([
        'https://ferraraphoto.test/api/v1/storage-profiles' => Http::response(['data' => ['id' => 'cloud']], 200),
    ]);

    $profile = StorageProfile::factory()->create(['id' => 'cloud']);

    $result = proofgen_api_client()->upsertStorageProfile($profile);

    expect($result)->toBe(['id' => 'cloud']);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://ferraraphoto.test/api/v1/storage-profiles'
        && $request['id'] === 'cloud'
        && $request['fingerprint'] === $profile->fingerprint);
});

it('throws ferraraphoto API exceptions from error envelopes', function () {
    Http::fake([
        'https://ferraraphoto.test/api/v1/shows' => Http::response([
            'error' => [
                'code' => 'validation_error',
                'message' => 'The show slug is invalid.',
                'context' => ['slug' => ['required']],
            ],
        ], 422),
    ]);

    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));

    try {
        proofgen_api_client()->upsertShow(Show::find('SHOW1'));
        $this->fail('Expected ferraraphoto API exception.');
    } catch (FerraraphotoApiException $exception) {
        expect($exception->apiCode)->toBe('validation_error')
            ->and($exception->status)->toBe(422)
            ->and($exception->context)->toBe(['slug' => ['required']])
            ->and($exception->getMessage())->toBe('The show slug is invalid.');
    }
});

it('retries server errors before returning data', function () {
    Http::fakeSequence()
        ->push(['error' => ['code' => 'server_error', 'message' => 'Try again']], 500)
        ->push(['data' => ['status' => 'ok']], 200);

    $result = proofgen_api_client()->profileHealth('cloud');

    expect($result)->toBe(['status' => 'ok']);

    Http::assertSentCount(2);
});

it('fails fast when no API token is configured', function () {
    proofgen_api_client('')->profileHealth('cloud');
})->throws(FerraraphotoApiException::class, 'Ferraraphoto API token is not configured.');
