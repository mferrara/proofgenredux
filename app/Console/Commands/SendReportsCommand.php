<?php

namespace App\Console\Commands;

use App\Services\Feedback\FeedbackReporter;
use Illuminate\Console\Command;

class SendReportsCommand extends Command
{
    protected $signature = 'proofgen:report:send';

    protected $description = 'Send any problem reports that were saved but could not be sent yet';

    public function handle(FeedbackReporter $reporter): int
    {
        $results = $reporter->sendPending();

        if ($results === []) {
            $this->line('No reports are waiting to be sent.');
        }

        foreach ($results as $report) {
            $report['status'] === 'sent'
                ? $this->info('Sent: '.$report['issue_url'])
                : $this->warn('Still waiting ('.$report['title'].'): '.$report['send_error']);
        }

        return self::SUCCESS;
    }
}
