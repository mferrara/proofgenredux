<?php

namespace App\Services\Feedback;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Files a report from an install: written to disk first (shows have bad
 * wifi), then sent as an issue to a private GitHub repo. A repeat of the same
 * problem becomes a comment on the open issue instead of a new one.
 *
 * Everything is redacted before it is stored, so what is on disk is exactly
 * what would be sent.
 */
class FeedbackReporter
{
    public const SEVERITIES = ['low', 'normal', 'high', 'blocking'];

    public function __construct(
        private ReportRedactor $redactor,
        private DiagnosticsCollector $diagnostics,
    ) {}

    /**
     * @param  array{title: string, what: string, expected?: ?string, suggestion?: ?string, severity?: ?string, filed_by?: ?string, llm?: bool}  $input
     * @return array<string, mixed> the stored report, including its `path`
     */
    public function file(array $input, int $logLines = 40): array
    {
        $severity = in_array($input['severity'] ?? null, self::SEVERITIES, true) ? $input['severity'] : 'normal';
        $title = $this->redactor->redact(trim($input['title']));

        $report = [
            'id' => now()->format('Ymd-His').'-'.Str::slug(Str::limit($title, 40, '')),
            'status' => 'pending',
            'filed_at' => now()->toIso8601String(),
            'install' => $this->redactor->redact((string) (config('proofgen.feedback.install_name') ?: gethostname())),
            'filed_by' => $this->redactor->redact(trim((string) ($input['filed_by'] ?? '')) ?: 'unknown'),
            'llm' => (bool) ($input['llm'] ?? false),
            'severity' => $severity,
            'title' => $title,
            'what' => $this->redactor->redact(trim($input['what'])),
            'expected' => $this->redactor->redact(trim((string) ($input['expected'] ?? ''))),
            'suggestion' => $this->redactor->redact(trim((string) ($input['suggestion'] ?? ''))),
            'signature' => $this->signature($title),
            'diagnostics' => $this->redactArray($this->diagnostics->collect($logLines)),
            'issue_url' => null,
            'send_error' => null,
        ];

        $report['path'] = $this->directory().'/'.$report['id'].'.json';
        $this->write($report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed> the report with its new status
     */
    public function send(array $report): array
    {
        $token = (string) config('proofgen.feedback.token');
        $repo = (string) config('proofgen.feedback.repo');

        if ($token === '' || $repo === '') {
            return $this->keepPending($report, 'No feedback token is configured (PROOFGEN_FEEDBACK_TOKEN).');
        }

        try {
            $existing = $this->openIssueWithSignature($repo, $token, $report['signature']);

            $response = $existing !== null
                ? $this->github($token)->post("https://api.github.com/repos/{$repo}/issues/{$existing}/comments", [
                    'body' => "Seen again.\n\n".$this->body($report),
                ])
                : $this->createIssue($repo, $token, $report);
        } catch (ConnectionException $exception) {
            return $this->keepPending($report, 'Could not reach GitHub: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->keepPending($report, 'GitHub answered HTTP '.$response->status().': '.Str::limit((string) $response->json('message'), 200));
        }

        $report['status'] = 'sent';
        $report['issue_url'] = $response->json('html_url');
        $report['send_error'] = null;
        $this->write($report);

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>> every report that was pending, after trying to send it
     */
    public function sendPending(): array
    {
        $results = [];

        foreach (File::glob($this->directory().'/*.json') as $path) {
            $report = json_decode((string) File::get($path), true);

            if (is_array($report) && ($report['status'] ?? null) === 'pending') {
                $results[] = $this->send(['path' => $path] + $report);
            }
        }

        return $results;
    }

    public function directory(): string
    {
        return storage_path('app/reports');
    }

    /**
     * Same problem, same signature: digits are ignored so "class 005" and
     * "class 010" of one failure collapse into one issue.
     */
    private function signature(string $title): string
    {
        return substr(sha1((string) preg_replace('/\d+/', '#', Str::lower(Str::squish($title)))), 0, 12);
    }

    private function createIssue(string $repo, string $token, array $report)
    {
        $payload = [
            'title' => '['.$report['severity'].'] '.$report['title'],
            'body' => $this->body($report),
            'labels' => ['severity:'.$report['severity'], $report['llm'] ? 'from:llm' : 'from:person'],
        ];

        $response = $this->github($token)->post("https://api.github.com/repos/{$repo}/issues", $payload);

        // A token limited to "Issues: write" may not be allowed to apply labels.
        if (in_array($response->status(), [403, 422], true)) {
            unset($payload['labels']);
            $response = $this->github($token)->post("https://api.github.com/repos/{$repo}/issues", $payload);
        }

        return $response;
    }

    private function openIssueWithSignature(string $repo, string $token, string $signature): ?int
    {
        $response = $this->github($token)->get("https://api.github.com/repos/{$repo}/issues", [
            'state' => 'open',
            'per_page' => 100,
        ]);

        foreach ((array) ($response->successful() ? $response->json() : []) as $issue) {
            if (str_contains((string) ($issue['body'] ?? ''), $this->marker($signature))) {
                return (int) $issue['number'];
            }
        }

        return null;
    }

    private function github(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20);
    }

    private function body(array $report): string
    {
        $sections = array_filter([
            'What happened' => $report['what'],
            'Expected' => $report['expected'],
            'Suggested fix' => $report['suggestion'],
        ]);

        $body = '';
        foreach ($sections as $heading => $text) {
            $body .= "### {$heading}\n\n{$text}\n\n";
        }

        $body .= "### Filed by\n\n".$report['filed_by'].($report['llm'] ? ' (LLM session)' : '')
            .' on **'.$report['install'].'** at '.$report['filed_at'].', severity **'.$report['severity']."**\n\n";

        $body .= "<details><summary>Diagnostics (collected and redacted on the install)</summary>\n\n```json\n"
            .json_encode($report['diagnostics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```\n\n</details>\n\n";

        return $body.$this->marker($report['signature']);
    }

    private function marker(string $signature): string
    {
        return '<!-- proofgen-signature:'.$signature.' -->';
    }

    private function keepPending(array $report, string $reason): array
    {
        $report['status'] = 'pending';
        $report['send_error'] = $this->redactor->redact($reason);
        $this->write($report);

        return $report;
    }

    private function write(array $report): void
    {
        File::ensureDirectoryExists(dirname($report['path']));
        File::put($report['path'], json_encode(
            array_diff_key($report, ['path' => true]),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function redactArray(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = $this->redactor->redact($value);
            }
        });

        return $data;
    }
}
