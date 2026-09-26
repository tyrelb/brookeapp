# Security policy

BrookeApp holds trainers' client lists, contact details and money records, so security reports are welcome and taken seriously.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Email **Tyrel Burton at [tb@tyrel.ca](mailto:tb@tyrel.ca)** instead, with:

- what the problem is and what an attacker could do with it;
- the steps, requests or code needed to reproduce it;
- the version or commit you tested against, and whether it was a local install or a live site;
- how you would like to be credited, if at all.

You can expect an acknowledgement within a few days, and an update at least once a week until it is resolved. Please give a reasonable amount of time to release a fix before disclosing the issue publicly.

Test against your own install (see the README), never against someone else's live site or real client data.

## Supported versions

| Version | Supported |
|---|---|
| 1.x | Yes |

Fixes land on the default branch and are listed in [CHANGELOG.md](CHANGELOG.md). If you run your own install, keep it up to date.

## Scope

Reports are especially welcome for:

- one trainer seeing or changing another trainer's data;
- access to a client's Fitness Wallet page without its link;
- an administrator seeing trainers' clients or money, which by design they cannot;
- a suspended account continuing to act;
- email content a trainer can use to deceive their clients.

Findings that depend on a misconfigured server (for example `APP_DEBUG=true` in production, or no HTTPS) are out of scope, though documentation improvements are appreciated.
