# Security

If you find a vulnerability in Melfina, email the Dragonshorn Studios maintainers rather than opening a public issue.

When agent keys exist: hash at rest; show plaintext only at create/rotate. Do not put secrets in instruction files that will be screenshotted. Normalize VFS paths and reject `..`. Rate-limit MCP writes when that surface lands.

Laravel framework issues: report via [https://laravel.com/docs/contributions#security-vulnerabilities](https://laravel.com/docs/contributions#security-vulnerabilities).
