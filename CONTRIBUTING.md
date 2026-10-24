# Contributing

Melfina is a self-hosted Imp brain. Product locks live in [`docs/`](docs/README.md).

1. Open an issue or work an existing one.
2. Keep PRs small. Prefer Laravel defaults over new structure.
3. Do not add DaisyUI, Bootstrap, Material, Storybook, Mem0, or ImpPost mail.
4. Do not implement owner auth / agents / VFS / MCP / Shared ACL unless the ticket says so.
5. Tests are Pest. Run `php artisan test`. Use `php artisan make:test --pest` for new tests. After `composer update`, Boost refreshes guidelines via `boost:update`.

Console chrome (Agents · Files · Shared · Instructions · messages screen) is implemented — extend it in place; keep the modular monolith.
