<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

#[Signature('attendees:url')]
#[Description('Generate the private signed URL for the attendee report')]
class AttendeeReportUrl extends Command
{
    public function handle(): int
    {
        $this->line(URL::signedRoute('attendees.index'));

        return self::SUCCESS;
    }
}
