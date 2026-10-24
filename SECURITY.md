# Security

If you find a vulnerability in Melfina, report it privately via [GitHub security advisories](https://github.com/Dragonshorn-Studios/melfina/security/advisories/new) rather than opening a public issue.

How Melfina handles credentials and untrusted input:

- Agent, admin, fleet, and scoped API keys are hashed at rest; plaintext is shown once at create/rotate.
- VFS paths are normalized and reject `..`; MCP and tool routes are rate-limited.
- Do not put secrets in instruction files that will be screenshotted.

Laravel framework issues: report via [https://laravel.com/docs/contributions#security-vulnerabilities](https://laravel.com/docs/contributions#security-vulnerabilities).
