# Contributing

Fixes are welcome: open a pull request.

- The development stack is described in the [README](README.md#local-development): Docker and Castor, nothing else on the host.
- `castor qa` must pass before a pull request is opened. It runs what the CI runs: coding standard, static analysis, linters and the tests.
- A fix comes with a test that fails without it.

A security problem is not reported in a public issue: see [SECURITY.md](SECURITY.md).
