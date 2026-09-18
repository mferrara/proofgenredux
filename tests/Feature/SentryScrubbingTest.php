<?php

use App\Services\Feedback\SentryEventScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

/*
 * Whatever Sentry sends goes through the same redaction as problem reports.
 */

beforeEach(fn () => config([
    'proofgen.sftp.host' => '203.0.113.77',
    'proofgen.ferraraphoto.api_token' => 'website-secret-token-value',
    'proofgen.sftp.private_key' => '/Users/dad/Herd/proofgenredux/ferrara_privatekey.pem',
]));

it('is off without a DSN and never traces', function () {
    expect(config('sentry.dsn'))->toBeNull()
        ->and(config('sentry.traces_sample_rate'))->toBeNull()
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse();
});

it('redacts exception messages, log messages, breadcrumbs and extra context', function () {
    $event = Event::createEvent();
    $event->setMessage('rsync to forge@203.0.113.77 failed using /Users/dad/Herd/proofgenredux/ferrara_privatekey.pem');
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Bearer website-secret-token-value rejected by 198.51.100.4'))]);
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Uploading from /Users/dad/ProofgenShows/26AAC', ['host' => '203.0.113.77']),
    ]);
    $event->setExtra(['command' => 'ssh forge@203.0.113.77', 'nested' => ['key' => '/Users/dad/.ssh/id_ed25519']]);
    $event->setRequest([
        'url' => 'http://proofgenredux.test/show/26AAC',
        'data' => ['password' => 'hunter2'],
        'cookies' => ['session' => 'abc'],
        'headers' => ['authorization' => ['Bearer x'], 'accept' => ['text/html']],
    ]);

    $sent = SentryEventScrubber::beforeSend($event);
    $flat = json_encode([
        $sent->getMessage(),
        $sent->getExceptions()[0]->getValue(),
        $sent->getBreadcrumbs()[0]->getMessage(),
        $sent->getBreadcrumbs()[0]->getMetadata(),
        $sent->getExtra(),
        $sent->getRequest(),
    ]);

    expect($flat)
        ->not->toContain('203.0.113.77')
        ->not->toContain('198.51.100.4')
        ->not->toContain('website-secret-token-value')
        ->not->toContain('ferrara_privatekey')
        ->not->toContain('id_ed25519')
        ->not->toContain('/Users/dad')
        ->not->toContain('hunter2')
        ->not->toContain('"session"')
        ->not->toContain('authorization')
        // The useful parts survive.
        ->toContain('26AAC')
        ->toContain('rsync to')
        ->toContain('text\/html');
});
