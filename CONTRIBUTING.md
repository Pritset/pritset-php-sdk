# Contributing

Use PHP 8.3 or newer and Composer 2.

```bash
composer install
composer verify
composer validate --strict
composer audit
```

Changes to API behavior must remain compatible with `contract/openapi.yaml`. If the shared contract changes, update the vendored contract, fixtures, lock hash, tests, and README together.

Never commit credentials, `.env`, generated PDFs containing customer data, or Composer's `vendor` directory.
