<?php

use App\Models\Attendee;
use Illuminate\Support\Facades\URL;

it('rejects unsigned organizer report URLs', function () {
    $this->get('/organizers/attendees')->assertForbidden();
    $this->get('/organizers/attendees.csv')->assertForbidden();
});

it('shows attendees at the signed organizer URL', function () {
    $attendee = Attendee::factory()->create([
        'name' => 'Taylor Otwell',
        'email' => 'taylor@example.com',
    ]);

    $this->get(URL::signedRoute('attendees.index'))
        ->assertSuccessful()
        ->assertSee('1 registration')
        ->assertSee($attendee->name)
        ->assertSee($attendee->email)
        ->assertSee('Download CSV');
});

it('rejects a tampered organizer URL', function () {
    $signedUrl = URL::signedRoute('attendees.index');

    $this->get($signedUrl.'&tampered=yes')->assertForbidden();
});

it('downloads a HubSpot-ready CSV from its own signed URL', function () {
    Attendee::factory()->create([
        'name' => '=Potential Formula',
        'email' => 'person@example.com',
        'created_at' => '2026-08-14 12:30:00',
    ]);

    $response = $this->get(URL::signedRoute('attendees.csv'))
        ->assertSuccessful()
        ->assertDownload('attendees-'.now()->format('Y-m-d').'.csv')
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())
        ->toContain('Name,Email,"Registered At"')
        ->toContain("\"'=Potential Formula\",person@example.com,\"2026-08-14 12:30:00\"");
});

it('prints the permanent organizer URL from artisan', function () {
    $this->artisan('attendees:url')
        ->expectsOutput(URL::signedRoute('attendees.index'))
        ->assertSuccessful();
});

it('never exposes attendee email addresses on public game screens', function () {
    $attendee = Attendee::factory()->create();

    $this->get('/')->assertSuccessful()->assertDontSee($attendee->email);
    $this->get('/board')->assertSuccessful()->assertDontSee($attendee->email);
});
