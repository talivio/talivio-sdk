# Security Policy

## Supported versions

Only the latest minor release line of `talivio/sdk` receives security fixes.
Older tags are not patched — upgrade before reporting an issue against them.

| Version | Supported |
| ------- | --------- |
| 1.23.x  | ✅        |
| < 1.23  | ❌        |

## Reporting a vulnerability

**Do not open a public GitHub issue for a security problem.**

Report privately through either channel:

- GitHub → the **Security** tab → *Report a vulnerability* (private advisory), or
- email **security@talivio.com**

Please include the affected version, a description of the impact, and the steps
needed to reproduce it. A proof-of-concept helps but is not required.

We aim to acknowledge a report within 3 business days and to ship a fix or a
mitigation plan within 30 days. We will credit you in the advisory unless you
ask us not to.

## Scope

In scope: this package's source — SSO/OAuth handling, the telemetry ingest
client, the GDPR deletion webhook, the AI gateway client, and the behavioural
human check.

Out of scope: the Talivio hub and the AI gateway themselves (report those to
security@talivio.com as well, but they are separate systems), and findings that
only apply to a product that has misconfigured this package.

## Credentials

This package holds no credentials of its own. Every secret — OAuth client
credentials, ingest token, webhook signing key, AI gateway key — is read from
the host application's environment at runtime. If you believe a credential has
been committed to this repository, treat it as a vulnerability and report it
through the channels above.
