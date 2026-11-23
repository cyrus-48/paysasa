# Security Policy

## Supported Versions

Security fixes are released for the latest minor version only. Paysasa is pre-1.0, so upgrade to the newest `0.x` release to receive fixes.

| Version | Supported          |
| ------- | ------------------ |
| 0.10.x  | :white_check_mark: |
| < 0.10  | :x:                |

## Dependencies

`composer.json` sets minimum versions of Laravel (`illuminate/*`), Guzzle and Carbon above the releases affected by published security advisories, so Composer cannot resolve a known-vulnerable version of them for an application that installs Paysasa. The `audit` workflow runs `composer audit` weekly against both the newest and the *oldest* allowed versions, and Dependabot proposes updates. Only Laravel versions that still receive security fixes from the Laravel team are supported (see https://laravel.com/docs/releases).

## Reporting a Vulnerability

If you discover a security vulnerability within Paysasa, please report it responsibly:

- **Do NOT** open a public GitHub issue.
- Email your findings directly to **cyrusmwendwa370@gmail.com**.
- Include steps to reproduce, affected drivers/versions, and proof-of-concept payloads where applicable.

### Response Timeline

- **Initial Acknowledgement**: Within 48 hours.
- **Assessment & Triage**: Within 5 business days.
- **Patch & Advisory**: Coordinated release with CVE assignment where appropriate.
