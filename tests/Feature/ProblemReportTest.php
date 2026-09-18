<?php

use App\Services\Feedback\FeedbackReporter;
use App\Services\Feedback\ReportRedactor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
 * `php artisan proofgen:report`: how an install tells the developer about a
 * problem. Redaction happens in code before anything is stored or sent.
 */

const FEEDBACK_API = 'https://api.github.com/repos/mferrara/proofgen-feedback';

beforeEach(function () {
    config([
        'proofgen.feedback.repo' => 'mferrara/proofgen-feedback',
        'proofgen.feedback.token' => 'github_pat_TESTTOKEN1234567890',
        'proofgen.feedback.install_name' => "Dad's MacBook",
        'proofgen.ferraraphoto.api_token' => 'website-secret-token-value',
        'proofgen.sftp.host' => '203.0.113.77',
        'proofgen.sftp.private_key' => '/Users/dad/Herd/proofgenredux/ferrara_privatekey.pem',
    ]);
    File::deleteDirectory(app(FeedbackReporter::class)->directory());
});

afterEach(fn () => File::deleteDirectory(app(FeedbackReporter::class)->directory()));

it('masks secrets, hosts, key paths and account names but keeps what identifies the problem', function () {
    $redacted = app(ReportRedactor::class)->redact(implode("\n", [
        'rsync to forge@203.0.113.77 failed; also tried 198.51.100.4 and 127.0.0.1',
        'Authorization: Bearer website-secret-token-value',
        'FERRARAPHOTO_API_TOKEN="abcd1234efgh5678"',
        'key /Users/dad/Herd/proofgenredux/ferrara_privatekey.pem and /Users/dad/.ssh/id_ed25519',
        'original at /Users/dad/ProofgenShows/26AAC/005/originals/26AAC_00002.jpg',
        'mail mike@example.com, photo sha1 52534bae0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f',
        'opaque AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKlMnOpQr',
    ]));

    expect($redacted)
        ->not->toContain('203.0.113.77')
        ->not->toContain('198.51.100.4')
        ->not->toContain('website-secret-token-value')
        ->not->toContain('abcd1234efgh5678')
        ->not->toContain('ferrara_privatekey')
        ->not->toContain('id_ed25519')
        ->not->toContain('/Users/dad')
        ->not->toContain('mike@example.com')
        ->not->toContain('AbCdEfGhIjKlMnOpQrStUvWxYz0123456789')
        // Still useful to the developer:
        ->toContain('127.0.0.1')
        ->toContain('~/ProofgenShows/26AAC/005/originals/26AAC_00002.jpg')
        ->toContain('52534bae0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f');
});

it('saves the redacted report and opens an issue in the private inbox', function () {
    Http::fake([
        FEEDBACK_API.'/issues?*' => Http::response([]),
        FEEDBACK_API.'/issues' => Http::response(['html_url' => 'https://github.com/mferrara/proofgen-feedback/issues/7'], 201),
    ]);

    $this->artisan('proofgen:report', [
        '--title' => 'Import failed creating the archive folder for class 005',
        '--what' => 'Unable to create a directory at 26AAC/005 while uploading to forge@203.0.113.77',
        '--expected' => 'All 6 photos import on the first try',
        '--suggestion' => 'Tolerate an existing folder',
        '--severity' => 'high',
        '--filed-by' => 'Claude Code session',
        '--llm' => true,
        '--log-lines' => 0,
    ])->expectsOutputToContain('Sent: https://github.com/mferrara/proofgen-feedback/issues/7')->assertSuccessful();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST') {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bearer github_pat_TESTTOKEN1234567890')
            && $request['title'] === '[high] Import failed creating the archive folder for class 005'
            && in_array('severity:high', $request['labels'], true)
            && in_array('from:llm', $request['labels'], true)
            && str_contains($request['body'], "Dad's MacBook")
            && str_contains($request['body'], 'Tolerate an existing folder')
            && ! str_contains($request['body'], '203.0.113.77');
    });

    $stored = json_decode(File::get(File::glob(app(FeedbackReporter::class)->directory().'/*.json')[0]), true);
    expect($stored['status'])->toBe('sent')
        ->and($stored['what'])->not->toContain('203.0.113.77')
        ->and($stored['diagnostics'])->toHaveKeys(['app', 'database', 'workers', 'storage', 'delivery']);
});

it('comments on the open issue when the same problem is reported again', function () {
    $reporter = app(FeedbackReporter::class);
    $first = $reporter->file(['title' => 'Import failed creating the archive folder for class 005', 'what' => 'x'], 0);

    Http::fake([
        FEEDBACK_API.'/issues?*' => Http::response([['number' => 7, 'body' => 'earlier <!-- proofgen-signature:'.$first['signature'].' -->']]),
        FEEDBACK_API.'/issues/7/comments' => Http::response(['html_url' => 'https://github.com/mferrara/proofgen-feedback/issues/7#issuecomment-1'], 201),
    ]);

    // A different class number is the same problem.
    $again = $reporter->send($reporter->file(['title' => 'Import failed creating the archive folder for class 010', 'what' => 'y'], 0));

    expect($again['status'])->toBe('sent')->and($again['issue_url'])->toContain('issuecomment');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/issues'));
});

it('keeps the report for later when it cannot be sent, and sends it with the next one', function () {
    config(['proofgen.feedback.token' => null]);

    // Http::preventStrayRequests() is active: without a token nothing may be requested.
    $this->artisan('proofgen:report', ['--title' => 'Wifi is down at the show', '--what' => 'No network', '--log-lines' => 0])
        ->expectsOutputToContain('Kept for later')
        ->assertSuccessful();

    config(['proofgen.feedback.token' => 'github_pat_TESTTOKEN1234567890']);
    Http::fake([
        FEEDBACK_API.'/issues?*' => Http::response([]),
        FEEDBACK_API.'/issues' => Http::response(['html_url' => 'https://github.com/mferrara/proofgen-feedback/issues/8'], 201),
    ]);

    $this->artisan('proofgen:report:send')->expectsOutputToContain('Sent: https://github.com/mferrara/proofgen-feedback/issues/8');
});

it('retries without labels when the token may not apply them', function () {
    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        if ($request->method() === 'GET') {
            return Http::response([]);
        }

        return ++$attempts === 1
            ? Http::response(['message' => 'Validation Failed'], 422)
            : Http::response(['html_url' => 'https://github.com/mferrara/proofgen-feedback/issues/9'], 201);
    });

    $reporter = app(FeedbackReporter::class);
    $sent = $reporter->send($reporter->file(['title' => 'Labels not permitted', 'what' => 'x'], 0));

    expect($sent['status'])->toBe('sent')->and($attempts)->toBe(2);
});

it('refuses a report without a title or a description', function () {
    $this->artisan('proofgen:report', ['--title' => 'Only a title', '--no-interaction' => true])->assertFailed();
});
