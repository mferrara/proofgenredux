<?php

namespace App\Console\Commands;

use App\Services\Feedback\FeedbackReporter;
use Illuminate\Console\Command;

/**
 * How a person or an LLM session working on an install tells the developer
 * about a problem. See docs/FEEDBACK.md.
 */
class FileReportCommand extends Command
{
    protected $signature = 'proofgen:report
        {--title= : One line naming the problem}
        {--what= : What happened (use "-" to read it from standard input)}
        {--expected= : What should have happened instead}
        {--suggestion= : A suggested fix, if there is one}
        {--severity=normal : low, normal, high, or blocking (work cannot continue)}
        {--filed-by= : Who is filing, e.g. "Claude Code session" or "Dad"}
        {--llm : The report is written by an LLM session}
        {--log-lines=40 : How many recent log lines to attach (0 for none)}
        {--no-send : Save the report without sending it}';

    protected $description = 'File a problem report for the developer (saved locally, then sent to the private feedback inbox)';

    public function handle(FeedbackReporter $reporter): int
    {
        $title = $this->option('title') ?: ($this->input->isInteractive() ? $this->ask('One line naming the problem') : null);
        $what = $this->option('what') === '-' ? stream_get_contents(STDIN) : $this->option('what');
        $what = $what ?: ($this->input->isInteractive() ? $this->ask('What happened?') : null);

        if (blank($title) || blank($what)) {
            $this->error('A report needs at least --title and --what.');

            return self::FAILURE;
        }

        if (! in_array($this->option('severity'), FeedbackReporter::SEVERITIES, true)) {
            $this->error('--severity must be one of: '.implode(', ', FeedbackReporter::SEVERITIES));

            return self::FAILURE;
        }

        $report = $reporter->file([
            'title' => $title,
            'what' => $what,
            'expected' => $this->option('expected'),
            'suggestion' => $this->option('suggestion'),
            'severity' => $this->option('severity'),
            'filed_by' => $this->option('filed-by'),
            'llm' => (bool) $this->option('llm'),
        ], (int) $this->option('log-lines'));

        $this->info('Saved (already redacted): '.$report['path']);

        if ($this->option('no-send')) {
            $this->line('Not sent (--no-send). Send later with: php artisan proofgen:report:send');

            return self::SUCCESS;
        }

        // Anything that could not be sent earlier goes out with this one.
        foreach ($reporter->sendPending() as $sent) {
            $sent['status'] === 'sent'
                ? $this->info('Sent: '.$sent['issue_url'])
                : $this->warn('Kept for later ('.$sent['title'].'): '.$sent['send_error']);
        }

        return self::SUCCESS;
    }
}
