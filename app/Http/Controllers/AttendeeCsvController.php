<?php

namespace App\Http\Controllers;

use App\Models\Attendee;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendeeCsvController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($stream, ['Name', 'Email', 'Registered At']);

            Attendee::query()
                ->select(['id', 'name', 'email', 'created_at'])
                ->oldest('id')
                ->lazyById()
                ->each(function (Attendee $attendee) use ($stream): void {
                    fputcsv($stream, [
                        $this->safeCsvValue($attendee->name),
                        $this->safeCsvValue($attendee->email),
                        $attendee->created_at->utc()->format('Y-m-d H:i:s'),
                    ]);
                });

            fclose($stream);
        }, 'attendees-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function safeCsvValue(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
