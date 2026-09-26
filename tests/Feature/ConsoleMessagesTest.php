<?php

use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->sender = Agent::factory()->create(['name' => 'sender-one']);
    $this->recipient = Agent::factory()->create(['name' => 'recipient-one']);
});

it('requires authentication and owner status', function () {
    $this->get('/console/messages')->assertRedirect();

    $this->actingAs(User::factory()->create(['is_owner' => false]))
        ->get('/console/messages')
        ->assertForbidden();
});

it('renders as AgentPost by default', function () {
    $this->actingAs($this->owner)
        ->get('/console/messages')
        ->assertOk()
        ->assertSee('AgentPost')
        ->assertSeeText('AGENTPOST');
});

it('uses the configured label in nav, heading, and title', function () {
    config()->set('melfina.messages_label', 'ImpPost');

    $response = $this->actingAs($this->owner)->get('/console/messages')->assertOk();

    expect($response->viewData('label'))->toBe('ImpPost');
    $response->assertSee('ImpPost', false)
        ->assertSeeText('IMPPOST');
});

it('trims the label and falls back to AgentPost when empty', function () {
    config()->set('melfina.messages_label', '   ');

    expect(AgentMessage::messagesLabel())->toBe('AgentPost');

    config()->set('melfina.messages_label', '  Parcel Post  ');
    expect(AgentMessage::messagesLabel())->toBe('Parcel Post');
});

it('escapes a label containing HTML', function () {
    config()->set('melfina.messages_label', '<b>Mail</b>');

    $this->actingAs($this->owner)
        ->get('/console/messages')
        ->assertOk()
        ->assertDontSee('<b>Mail</b>', false)
        ->assertSee('&lt;b&gt;Mail&lt;/b&gt;', false);
});

it('lists messages newest first with sender, recipient, subject, and state', function () {
    AgentMessage::factory()->between($this->sender, $this->recipient)->create([
        'subject' => 'first', 'body' => 'older',
    ]);
    $latest = AgentMessage::factory()->between($this->sender, $this->recipient)->create([
        'subject' => 'latest dispatch', 'body' => 'newest',
    ]);

    $response = $this->actingAs($this->owner)->get('/console/messages')->assertOk();

    expect($response->viewData('messages')->items()[0]->id)->toBe($latest->id);
    $response->assertSee('latest dispatch')->assertSee('sender-one')->assertSee('recipient-one');
});

it('filters by agent and direction', function () {
    $other = Agent::factory()->create(['name' => 'outsider']);
    $inbound = AgentMessage::factory()->between($this->sender, $this->recipient)->create(['subject' => 'to recipient']);
    $outbound = AgentMessage::factory()->between($this->recipient, $this->sender)->create(['subject' => 'from recipient']);
    AgentMessage::factory()->between($this->sender, $other)->create(['subject' => 'unrelated']);

    $ids = fn (string $query) => collect($this->actingAs($this->owner)
        ->get('/console/messages?'.$query)->assertOk()->viewData('messages')->items())->pluck('id');

    // agent alone: everything the recipient is a party to
    expect($ids("agent={$this->recipient->id}")->all())
        ->toEqualCanonicalizing([$inbound->id, $outbound->id]);

    expect($ids("agent={$this->recipient->id}&direction=inbox")->all())->toBe([$inbound->id]);
    expect($ids("agent={$this->recipient->id}&direction=sent")->all())->toBe([$outbound->id]);
});

it('filters by read and acknowledgement state', function () {
    $unread = AgentMessage::factory()->between($this->sender, $this->recipient)->create();
    $read = AgentMessage::factory()->between($this->sender, $this->recipient)->read()->create();
    $acked = AgentMessage::factory()->between($this->sender, $this->recipient)->acknowledged()->create();

    $ids = fn (string $query) => collect($this->actingAs($this->owner)
        ->get('/console/messages?'.$query)->assertOk()->viewData('messages')->items())->pluck('id');

    expect($ids('read=unread')->all())->toBe([$unread->id]);
    expect($ids('read=read')->all())->toEqualCanonicalizing([$read->id, $acked->id]);
    expect($ids('ack=unacked')->all())->toEqualCanonicalizing([$unread->id, $read->id]);
    expect($ids('ack=acked')->all())->toBe([$acked->id]);
});

it('paginates large histories', function () {
    AgentMessage::factory()->between($this->sender, $this->recipient)->count(55)->create();

    $page1 = $this->actingAs($this->owner)->get('/console/messages')->assertOk()->viewData('messages');
    $page2 = $this->actingAs($this->owner)->get('/console/messages?page=2')->assertOk()->viewData('messages');

    expect($page1->perPage())->toBe(50)
        ->and($page1->total())->toBe(55)
        ->and($page2->items())->toHaveCount(5);
});

it('shows full body and attachments on the detail screen without mutating state', function () {
    $message = AgentMessage::factory()->between($this->sender, $this->recipient)->create([
        'subject' => 'dock report',
        'body' => 'full body text here',
    ]);
    $message->attachments()->create(['name' => 'notes.txt', 'content' => 'snapshot content']);

    $this->actingAs($this->owner)
        ->get('/console/messages/'.$message->id)
        ->assertOk()
        ->assertSee('dock report')
        ->assertSee('full body text here')
        ->assertSee('notes.txt')
        ->assertSee('snapshot content');

    expect($message->fresh()->read_at)->toBeNull()
        ->and($message->fresh()->acknowledged_at)->toBeNull();
});

it('shows an empty state when there are no messages', function () {
    $this->actingAs($this->owner)
        ->get('/console/messages')
        ->assertOk()
        ->assertSee('No messages yet');
});
