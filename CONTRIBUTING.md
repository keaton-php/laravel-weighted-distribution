# Contributing

Contributions are welcome and will be fully credited.

Please open an issue first for anything larger than a bug fix. The package is small on purpose, and it's better to talk a feature through before you write it.

## Pull requests

- Branch from `1.x` and open the pull request against `1.x`.
- Keep one change per pull request.
- Add a test that fails without your change.
- Add a line to `CHANGELOG.md` under `Unreleased`.
- Update the README and the docs when behavior changes. The PHP blocks in them are run by `tests/Feature/DocsExamplesTest.php`.

## Running the tests

```bash
composer install
composer test
```

The tests run on Orchestra Testbench. They don't need a database or a cache server.

## Code style

```bash
composer format
composer analyse
```

## Security

Please don't report security problems in a public issue. See [SECURITY.md](SECURITY.md).
