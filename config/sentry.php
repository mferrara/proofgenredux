<?php

use App\Services\Delivery\DeliveryTargetException;
use App\Services\Feedback\SentryEventScrubber;
use App\Services\Ferraraphoto\WebsiteShowMissingException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| Sentry
|--------------------------------------------------------------------------
|
| Unhandled errors and failed jobs from an install, without anyone having to
| notice and report them. Off unless SENTRY_LARAVEL_DSN is set in .env.
| Problem reports written by people and LLM sessions are separate: see
| docs/FEEDBACK.md.
|
| Everything sent is run through the same redaction as problem reports
| (SentryEventScrubber). Performance tracing is off: there is nothing to tune
| on a single-user desktop app, and it would send far more data.
|
*/

return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // Which machine an error came from. Falls back to the hostname.
    'server_name' => env('PROOFGEN_INSTALL_NAME') ?: gethostname(),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // The git tag, so an error is tied to the version that produced it. Only
    // looked up when Sentry is on; installs cache config, so this runs once per
    // update rather than per request.
    'release' => env('SENTRY_RELEASE') ?: (env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))
        ? (trim((string) @shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' describe --tags --always 2>/dev/null')) ?: null)
        : null),

    'sample_rate' => 1.0,
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,

    'send_default_pii' => false,

    'before_send' => [SentryEventScrubber::class, 'beforeSend'],
    'before_breadcrumb' => [SentryEventScrubber::class, 'beforeBreadcrumb'],

    // Shows have bad wifi. A failing job must not hang on error reporting.
    'http_connect_timeout' => 2,
    'http_timeout' => 4,

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => true,
        // Bindings can hold file paths and slugs; the SQL text is enough.
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
    ],

    'ignore_exceptions' => [
        AuthenticationException::class,
        ValidationException::class,
        NotFoundHttpException::class,
        // Expected, operator-facing stops; they are shown in the app.
        DeliveryTargetException::class,
        WebsiteShowMissingException::class,
    ],
];
