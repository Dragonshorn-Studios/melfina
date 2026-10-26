<?php

use App\Models\Agent;
use App\Models\User;
use App\Services\SchemaStatus;
use Illuminate\Support\Facades\File;
use ReflectionProperty;

function withPendingMigration(Closure $callback): void
{
    $dir = sys_get_temp_dir().'/melfina-schema-setup-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);

    $name = '2099_01_01_000000_schema_setup_probe';
    File::put($dir.'/'.$name.'.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
PHP);

    $migrator = app('migrator');
    $paths = new ReflectionProperty($migrator, 'paths');
    $original = $paths->getValue($migrator);
    $migrator->path($dir);

    try {
        $callback($name);
    } finally {
        $paths->setValue($migrator, $original);
        File::deleteDirectory($dir);
    }
}

it('lets the owner into the console when the schema is current', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('console.dashboard'))
        ->assertOk();

    $this->actingAs($owner)
        ->get(route('console.setup.show'))
        ->assertRedirect(route('console.dashboard'));
});

it('sends the owner to setup instead of the rest of the console when migrations are pending', function () {
    $owner = User::factory()->owner()->create();

    withPendingMigration(function (string $name) use ($owner) {
        expect(app(SchemaStatus::class)->isCurrent())->toBeFalse();

        $this->actingAs($owner)
            ->get(route('console.dashboard'))
            ->assertRedirect(route('console.setup.show'));

        $this->actingAs($owner)
            ->get(route('console.knowledge.index'))
            ->assertRedirect(route('console.setup.show'));

        $this->actingAs($owner)
            ->get(route('console.setup.show'))
            ->assertOk()
            ->assertSee('You need to run migrations', false)
            ->assertSee($name, false)
            ->assertSee('MCP and the HTTP tools API stay disabled', false)
            ->assertSee('run migrations', false);
    });
});

it('keeps login, logout, and health reachable while migrations are pending', function () {
    $owner = User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => bcrypt('password'),
    ]);

    withPendingMigration(function () use ($owner) {
        $this->get('/up')->assertOk();
        $this->get(route('login'))->assertOk();

        $this->post(route('login'), [
            'email' => 'owner@example.com',
            'password' => 'password',
        ])->assertRedirect(route('console.dashboard'));

        $this->assertAuthenticatedAs($owner);

        $this->get(route('console.dashboard'))
            ->assertRedirect(route('console.setup.show'));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    });
});

it('answers the knowledge API with 503 until migrations finish', function () {
    $owner = User::factory()->owner()->create();

    withPendingMigration(function () use ($owner) {
        $this->actingAs($owner)
            ->getJson(route('api.knowledge.graph'))
            ->assertServiceUnavailable()
            ->assertJson([
                'ok' => false,
                'error' => 'schema outdated',
            ]);
    });
});

it('answers MCP and the HTTP tools API with 503 until migrations finish', function () {
    User::factory()->owner()->create();
    Agent::factory()->withKey()->create();

    withPendingMigration(function () {
        mcpCall('initialize', [], 'melf_testkey')->assertServiceUnavailable()
            ->assertJson([
                'ok' => false,
                'error' => 'schema outdated',
            ]);

        $this->getJson('/api/tools', [
            'Authorization' => 'Bearer melf_testkey',
        ])->assertServiceUnavailable()
            ->assertJson([
                'ok' => false,
                'error' => 'schema outdated',
            ]);

        $this->postJson('/api/enroll', [
            'key' => 'melfe_unused',
            'runtime_id' => 'r1',
            'name' => 'probe',
        ])->assertServiceUnavailable();
    });
});

it('runs pending migrations from the console and then enables MCP', function () {
    $owner = User::factory()->owner()->create();
    Agent::factory()->withKey()->create();

    withPendingMigration(function (string $name) use ($owner) {
        $this->actingAs($owner)
            ->from(route('console.setup.show'))
            ->post(route('console.setup.run'))
            ->assertOk()
            ->assertSee('ran', false)
            ->assertSee($name, false)
            ->assertSee('Schema is current', false)
            ->assertSee('continue to console', false);

        expect(app(SchemaStatus::class)->isCurrent())->toBeTrue();

        $this->actingAs($owner)
            ->get(route('console.dashboard'))
            ->assertOk();

        mcpCall('initialize', [], 'melf_testkey')->assertOk();

        $this->getJson('/api/tools', [
            'Authorization' => 'Bearer melf_testkey',
        ])->assertOk();
    });
});

it('streams a per-migration progress report', function () {
    $owner = User::factory()->owner()->create();

    withPendingMigration(function (string $name) use ($owner) {
        $response = $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/x-ndjson'])
            ->post(route('console.setup.run'));

        $response->assertOk();

        $body = $response->streamedContent();

        expect($body)->toContain($name)
            ->and($body)->toContain('"status":"running"')
            ->and($body)->toContain('"status":"ran"')
            ->and($body)->toContain('"status":"done"');

        expect(app(SchemaStatus::class)->isCurrent())->toBeTrue();
    });
});

it('forbids non-owners from the setup screen', function () {
    $user = User::factory()->create(['is_owner' => false]);

    $this->actingAs($user)
        ->get(route('console.setup.show'))
        ->assertForbidden();
});
