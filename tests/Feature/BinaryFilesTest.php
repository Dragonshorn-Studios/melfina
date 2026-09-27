<?php

use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\SharedAcl;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use App\Models\User;
use App\Models\VfsFile;
use Illuminate\Http\UploadedFile;

const PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create();
    $this->png = base64_decode(PNG_BYTES);
});

function putFile(string $path, string $bytes, string $mime = 'image/png', ?string $bearer = 'melf_testkey')
{
    return test()->call('PUT', '/api/files/'.$path, server: array_filter([
        'HTTP_AUTHORIZATION' => $bearer === null ? null : 'Bearer '.$bearer,
        'CONTENT_TYPE' => $mime,
    ]), content: $bytes);
}

function getFile(string $path, ?string $bearer = 'melf_testkey')
{
    return test()->call('GET', '/api/files/'.$path, server: array_filter([
        'HTTP_AUTHORIZATION' => $bearer === null ? null : 'Bearer '.$bearer,
    ]));
}

it('stores binary rows with a sniffed mime, kept out of the text column', function () {
    $file = VfsFile::writeBinaryFor($this->agent, 'img/p.png', $this->png, 'application/octet-stream');

    expect($file->isBinary())->toBeTrue()
        ->and($file->mime)->toBe('image/png')
        ->and($file->bytes())->toBe($this->png)
        ->and(VfsFile::readFor($this->agent, 'img/p.png'))->toBeNull()
        ->and(VfsFile::fileFor($this->agent, 'img/p.png')->bytes())->toBe($this->png);
});

it('lets text overwrite binary and binary overwrite text on the same path', function () {
    VfsFile::writeBinaryFor($this->agent, 'a.bin', $this->png);
    VfsFile::writeFor($this->agent, 'a.bin', 'now text');

    $file = VfsFile::fileFor($this->agent, 'a.bin');
    expect($file->isBinary())->toBeFalse()
        ->and($file->content)->toBe('now text')
        ->and($file->content_blob)->toBeNull();

    VfsFile::writeBinaryFor($this->agent, 'a.bin', $this->png);
    expect(VfsFile::fileFor($this->agent, 'a.bin')->bytes())->toBe($this->png);
});

it('rejects binaries over 10 MB', function () {
    VfsFile::writeBinaryFor($this->agent, 'huge.bin', str_repeat("\x00", VfsFile::MAX_BINARY_BYTES + 1));
})->throws(InvalidArgumentException::class);

it('round-trips raw bytes over PUT/GET with a dedicated key', function () {
    putFile('img/p.png', $this->png)
        ->assertOk()
        ->assertJson(['ok' => true, 'path' => 'img/p.png', 'mime' => 'image/png']);

    $response = getFile('img/p.png')->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->getContent())->toBe($this->png);
});

it('stores UTF-8 PUT bodies as text rows', function () {
    putFile('notes/n.txt', 'plain notes', 'text/plain')->assertOk();

    expect(VfsFile::fileFor($this->agent, 'notes/n.txt')->isBinary())->toBeFalse()
        ->and(VfsFile::readFor($this->agent, 'notes/n.txt'))->toBe('plain notes');
});

it('enforces auth and scoped-key permissions on the raw endpoints', function () {
    putFile('x.bin', $this->png)->assertOk();

    putFile('y.bin', $this->png, 'image/png', null)->assertUnauthorized();
    getFile('x.bin', null)->assertUnauthorized();
    getFile('x.bin', 'melf_dead')->assertUnauthorized();

    $readKey = AgentApiKey::issue($this->agent, 'reader', ['files:read']);
    getFile('x.bin', $readKey)->assertOk();
    putFile('z.bin', $this->png, 'image/png', $readKey)->assertForbidden();
});

it('rejects traversal and oversized raw uploads', function () {
    putFile('../evil.png', $this->png)->assertUnprocessable();
    putFile('big.bin', str_repeat("\x01", VfsFile::MAX_BINARY_BYTES + 1), 'application/octet-stream')
        ->assertUnprocessable();
});

it('writes and reads shared files with the space ACL', function () {
    $space = SharedSpace::factory()->create(['name' => 'fleet']);
    SharedAcl::factory()->for($space, 'sharedSpace')->for($this->agent)->create(['permission' => 'write']);

    $this->call('PUT', '/api/shared/fleet/files/logo.png', server: [
        'HTTP_AUTHORIZATION' => 'Bearer melf_testkey',
        'CONTENT_TYPE' => 'image/png',
    ], content: $this->png)->assertOk()->assertJson(['mime' => 'image/png']);

    $response = $this->call('GET', '/api/shared/fleet/files/logo.png', server: [
        'HTTP_AUTHORIZATION' => 'Bearer melf_testkey',
    ])->assertOk();

    expect($response->getContent())->toBe($this->png);
});

it('fails closed on shared spaces without a grant', function () {
    SharedSpace::factory()->create(['name' => 'fleet']);

    $this->call('PUT', '/api/shared/fleet/files/x.bin', server: [
        'HTTP_AUTHORIZATION' => 'Bearer melf_testkey',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], content: 'x')->assertNotFound();

    $this->call('GET', '/api/shared/fleet/files/x.bin', server: [
        'HTTP_AUTHORIZATION' => 'Bearer melf_testkey',
    ])->assertNotFound();
});

it('round-trips a binary through the write_file base64 hatch', function () {
    $this->postJson('/api/tools/write_file', [
        'path' => 'img/p.png',
        'content_base64' => PNG_BYTES,
        'mime' => 'image/png',
    ], ['Authorization' => 'Bearer melf_testkey'])->assertOk();

    expect(VfsFile::fileFor($this->agent, 'img/p.png')->isBinary())->toBeTrue();

    // read_file on an image returns MCP image content — the HTTP adapter
    // surfaces it as a content result carrying base64 + mimeType.
    $response = $this->postJson('/api/tools/read_file', ['path' => 'img/p.png'], [
        'Authorization' => 'Bearer melf_testkey',
    ])->assertOk();

    expect($response->json('resultType'))->toBe('content')
        ->and($response->json('result.content.0.type'))->toBe('image')
        ->and($response->json('result.content.0.data'))->toBe(PNG_BYTES)
        ->and($response->json('result.content.0.mimeType'))->toBe('image/png');
});

it('serves non-image binaries as base64 on demand', function () {
    VfsFile::writeBinaryFor($this->agent, 'blob.bin', "\x00\x01\x02raw", 'application/octet-stream');

    // Tool-level errors surface as ok:false at HTTP 400.
    $this->postJson('/api/tools/read_file', ['path' => 'blob.bin'], [
        'Authorization' => 'Bearer melf_testkey',
    ])->assertStatus(400)->assertJson(['ok' => false]);

    $response = $this->postJson('/api/tools/read_file', ['path' => 'blob.bin', 'format' => 'base64'], [
        'Authorization' => 'Bearer melf_testkey',
    ])->assertOk()->assertJson(['ok' => true]);

    expect(base64_decode(json_decode($response->json('result'), true)['content_base64']))
        ->toBe("\x00\x01\x02raw");
});

it('refuses a malformed base64 hatch', function () {
    $this->postJson('/api/tools/write_file', [
        'path' => 'x.bin',
        'content_base64' => 'not-base64!!!',
    ], ['Authorization' => 'Bearer melf_testkey'])->assertStatus(400)->assertJson(['ok' => false]);
});

it('reports real byte counts for binaries in the console listing', function () {
    VfsFile::writeBinaryFor($this->agent, 'img.png', $this->png, 'image/png');
    VfsFile::writeFor($this->agent, 'note.txt', 'hello');

    $response = $this->actingAs($this->owner)->get('/console/files/'.$this->agent->id);
    $response->assertOk();

    $sizes = collect($response->viewData('meta'));
    expect($sizes->get('img.png')->byte_size)->toBe(strlen($this->png));
    expect($sizes->get('note.txt')->byte_size)->toBe(5);
});

it('lets the owner upload, view, and stream binaries in the console', function () {
    $this->actingAs($this->owner)
        ->post(route('console.files.upload', $this->agent), [
            'file' => UploadedFile::fake()->createWithContent('shot.png', $this->png),
        ])->assertRedirect();

    $file = VfsFile::fileFor($this->agent, 'shot.png');
    expect($file->isBinary())->toBeTrue()->and($file->mime)->toBe('image/png');

    $this->actingAs($this->owner)
        ->get(route('console.files.view', ['agent' => $this->agent, 'path' => 'shot.png']))
        ->assertOk()
        ->assertSee('shot.png')
        ->assertSee('image/png');

    $this->actingAs($this->owner)
        ->get(route('console.files.raw', ['agent' => $this->agent, 'path' => 'shot.png']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline; filename="shot.png"');

    // Opening a binary in the editor redirects to the viewer.
    $this->actingAs($this->owner)
        ->get(route('console.files.edit', ['agent' => $this->agent, 'path' => 'shot.png']))
        ->assertRedirect(route('console.files.view', ['agent' => $this->agent, 'path' => 'shot.png', 'dir' => '']));
});

it('forces agent-authored HTML/SVG to download instead of rendering', function () {
    VfsFile::writeBinaryFor($this->agent, 'page.html', '<script>alert(1)</script>', 'text/html');

    $this->actingAs($this->owner)
        ->get(route('console.files.raw', ['agent' => $this->agent, 'path' => 'page.html']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('uploads into a shared space and streams it back', function () {
    $space = SharedSpace::factory()->create(['name' => 'fleet']);

    $this->actingAs($this->owner)
        ->post(route('console.shared.upload', $space), [
            'file' => UploadedFile::fake()->createWithContent('logo.png', $this->png),
        ])->assertRedirect();

    expect(SharedFile::fileFor($space, 'logo.png')->isBinary())->toBeTrue();

    $this->actingAs($this->owner)
        ->get(route('console.shared.file.raw', ['shared' => $space, 'path' => 'logo.png']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    // The space file page renders the image inline via the raw route.
    $this->actingAs($this->owner)
        ->get(route('console.shared.file', ['shared' => $space, 'path' => 'logo.png']))
        ->assertOk()
        ->assertSee('logo.png');
});
