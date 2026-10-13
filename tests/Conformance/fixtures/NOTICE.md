# Reference fixtures from OpenFGA

The directories beside this file, and `dsl-syntax-validation-cases.yaml`, are
copied unchanged from the OpenFGA `language` repository - the reference
implementation of the DSL transformer this package reimplements in PHP.

- Source: https://github.com/openfga/language/tree/main/tests/data
- Commit: `f558baace40cebd4548ab50b6d50ce65a940babb`
- Retrieved: 2026-10-13
- Licence: Apache License 2.0, (c) OpenFGA authors

They are vendored rather than downloaded so that the test suite needs no
network and so that a change upstream cannot silently alter what this package
claims to be tested against. Refreshing them is a deliberate act with a
commit of its own.

Using OpenFGA's own fixtures is the point: a transformer that agrees with
examples I wrote myself has proved nothing. These are the cases their
implementation is held to.
