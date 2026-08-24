# Security policy

Please report suspected vulnerabilities privately to `security@pritset.com`. Do not include access tokens, secrets, customer documents, or production payloads in a public issue.

Only the latest released minor version receives security fixes during the pre-1.0 period. Rotate any credential that may have appeared in logs, exception dumps, shell history, or source control.

The SDK rejects non-HTTPS API endpoints except explicit loopback addresses, disables redirects, and does not include credential-bearing HTTP exceptions as previous exceptions.
