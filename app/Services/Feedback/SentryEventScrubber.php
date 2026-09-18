<?php

namespace App\Services\Feedback;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Runs every outgoing Sentry event through {@see ReportRedactor}.
 *
 * Proofgen's errors quote server addresses, key paths, and home folders. The
 * SDK's own PII switch does not know about those, so the same redaction that
 * protects problem reports is applied to exception messages, log messages,
 * breadcrumbs, and extra context. Request bodies and cookies are dropped: this
 * app has nothing in them worth the risk.
 */
class SentryEventScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $redactor = app(ReportRedactor::class);

        if ($event->getMessage() !== null) {
            $event->setMessage($redactor->redact($event->getMessage()), $event->getMessageParams(), null);
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($redactor->redact($exception->getValue()));
        }

        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $breadcrumb) => self::breadcrumb($breadcrumb),
            $event->getBreadcrumbs(),
        ));

        $event->setExtra(self::redactArray($redactor, $event->getExtra()));

        $request = $event->getRequest();
        unset($request['data'], $request['cookies'], $request['env']);
        if (isset($request['headers'])) {
            unset($request['headers']['authorization'], $request['headers']['cookie'], $request['headers']['x-xsrf-token']);
        }
        if (isset($request['query_string'])) {
            $request['query_string'] = $redactor->redact((string) $request['query_string']);
        }
        $event->setRequest($request);

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        return self::breadcrumb($breadcrumb);
    }

    private static function breadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $redactor = app(ReportRedactor::class);

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            $breadcrumb = $breadcrumb->withMetadata($key, is_string($value)
                ? $redactor->redact($value)
                : (is_array($value) ? self::redactArray($redactor, $value) : $value));
        }

        return $breadcrumb->getMessage() === null
            ? $breadcrumb
            : $breadcrumb->withMessage($redactor->redact($breadcrumb->getMessage()));
    }

    private static function redactArray(ReportRedactor $redactor, array $data): array
    {
        array_walk_recursive($data, function (&$value) use ($redactor) {
            if (is_string($value)) {
                $value = $redactor->redact($value);
            }
        });

        return $data;
    }
}
