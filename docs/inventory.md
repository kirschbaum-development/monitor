# The Inventory

- [Introduction](#introduction)
- [monitor:points](#monitorpoints)
    - [What Is Shown](#what-is-shown)
    - [Formats](#formats)
    - [Paths](#paths)
    - [Checking](#checking)
- [The Rules](#the-rules)
- [Critical Namespaces](#critical-namespaces)
- [monitor:explain](#monitorexplain)
- [make:control-point](#makecontrol-point)
- [In CI](#in-ci)

## Introduction

The inventory is the list of every control point in the application, built by reading the code rather than running it. It answers which operations are critical and what happens when each one fails. The same scan runs a set of rules, so a point declared without an escalation, or a class in a critical namespace with no control point at all, fails the build.

## monitor:points

```bash
php artisan monitor:points
```

The command scans the configured paths for PHP files, parses each without executing it, and finds two kinds of point.

### What Is Shown

**Class-form points**, classes extending `Kirschbaum\Monitor\ControlPoint`, are described completely: name, domain, origin, profile, policies, limits, risks, whether they recover from `Throwable`, and their escalation. The description comes from the `#[Point]` attribute and from calling `control()` on an instance built without its constructor, which is why `control()` must not read constructor arguments. See [Control Points](control-points.md).

**Inline points**, `Monitor::control('name')` or `new Control('name')` in any class, are inventoried by name, file, line and enclosing class only. Their closures are not inspected, and the table says `not inspected` in the policy, risk and escalation columns. A name that is not a string literal is listed as `(dynamic)`.

The table has the columns Point, Form, Domain, Origin, Profile, Policies, Risks, Escalation and, when the [outcome store](store.md) is enabled, Last seen with the status of the point's most recent run. Findings follow the table, one line each, and a summary line counts points, errors and warnings.

### Formats

```bash
php artisan monitor:points --format=table   # the default
php artisan monitor:points --format=json
php artisan monitor:points --format=sarif
```

JSON prints `points` and `findings` as arrays. Each point carries `point`, `form`, `origin`, `domain`, `file`, `line`, `profile`, `policies`, `limits`, `risks`, `catch_all`, `escalation`, `dynamic_name`, `unreadable` and `notes`. Each finding carries `rule`, `level`, `message`, `point`, `file` and `line`.

SARIF 2.1.0 lists the findings as results with file locations relative to the project root, so code scanning shows them as annotations on the pull request. Every rule is declared in the tool's rule list.

### Paths

By default the scan covers `discovery.paths`, `['app']` relative to the project root:

```php
'discovery' => [
    'paths' => ['app'],
],
```

`--path` scans other directories instead, absolute or relative, and may be repeated:

```bash
php artisan monitor:points --path=app/ControlPoints --path=packages/billing/src
```

### Checking

```bash
php artisan monitor:points --check
```

`--check` exits 1 when any rule reported an error, and 0 otherwise. Warnings are printed but never fail the check. Combine it with a format when a machine reads the output:

```bash
php artisan monitor:points --check --format=sarif > monitor.sarif
```

## The Rules

Every rule is on unless switched off in `inventory.rules`:

```php
'inventory' => [
    'rules' => [
        'catch_all_without_escalation' => false,
    ],
],
```

| Rule | Level | Reports |
| --- | --- | --- |
| `duplicate_names` | error | `"payment.charge" is declared 2 times: App\ControlPoints\Payments\ChargeCard:14, App\Services\Legacy\Charger:41`. A name is the join key across code, records and tests; two points sharing one cannot be told apart anywhere downstream. |
| `name_pattern` | error | `"ChargeCard" does not match the configured point name pattern`. Names must match `point_name_pattern`, dotted lowercase by default. |
| `missing_escalation` | error | `"payment.refund" declares no escalation and no catch-all; an unexpected failure would leave silently`. Class-form points only. |
| `catch_all_without_escalation` | warning | `"filing.submit" recovers from Throwable with no escalation; every failure is swallowed`. Class-form points only. |
| `critical_namespace_uncontrolled` | error | `App\ControlPoints\Filings\Uncontrolled sits in a critical namespace but is not a control point and calls none`. See [Critical Namespaces](#critical-namespaces). |
| `dynamic_name` | warning | `a control point in App\Services\Sync takes its name from an expression; use a string literal`. Inline points only. |
| `unreadable_control` | error | `"payment.capture": control() could not be read statically: ...`. The class's `control()` threw when called without its constructor, so nothing else about the point can be trusted. |

The rules about risks and escalation see the class form only, because an inline point's closures are not inspected. That is why the guidelines send critical operations to the class form.

## Critical Namespaces

`critical_namespaces` lists namespaces whose classes are expected to be control points:

```php
'critical_namespaces' => [
    'App\\ControlPoints',
    'App\\Services\\Payments',
],
```

A class in one of them passes when it extends `ControlPoint`, or contains an inline `Monitor::control()` call. Interfaces, traits, enums, abstract classes, `Escalation` implementations and `Policy` implementations are not operations and are exempt. The same list drives the Pest expectation and the PHPStan rule described in [Testing](testing.md). With the list empty, the rule reports nothing.

## monitor:explain

Describes one point's contract in plain words, for a person or an agent:

```bash
php artisan monitor:explain payment.charge
```

```
payment.charge is a control point class, App\ControlPoints\Payments\ChargeCard, in the Payments domain with the "external" profile.
Policies: the "stripe" breaker opens after 5 failures within 60s for 120s; retry 2 time(s) with 200ms backoff.
Limits: should finish within 10s; the result must satisfy "charge must be settled".
It recovers from App\Exceptions\CardDeclined, App\Exceptions\InsufficientFunds.
App\Escalations\PagePayments is called when it escalates.
Last 24 hours: 40 succeeded, 1 recovered.
```

The history line appears when the [outcome store](store.md) is enabled. An inline point is described by its location and the note that only its name is known statically. `--json` prints the same description the JSON format of `monitor:points` uses for that point. The command exits 1 when no point has that name.

## make:control-point

Generates a control point class and its test:

```bash
php artisan make:control-point Payments/RefundCard
php artisan make:control-point Payments/RefundCard --point=payment.refund --profile=external
php artisan make:control-point Payments/RefundCard --no-test
php artisan make:control-point Payments/RefundCard --force
```

The class goes under `App\ControlPoints`, so `Payments/RefundCard` becomes `app/ControlPoints/Payments/RefundCard.php`. The test goes to `tests/Feature/ControlPoints/Payments/RefundCardTest.php`.

| Option | Meaning |
| --- | --- |
| `--point=` | The point name. Without it the name is derived: the directory segments in snake case, a dot, and the class in snake case, so `Payments/RefundCard` becomes `payments.refund_card` and a class with no directory gets `app.`. |
| `--profile=` | Adds `profile: '...'` to the attribute. |
| `--no-test` | Skip the test. |
| `--force` | Overwrite an existing class and test. Without it, an existing class is reported and nothing is written. |

The class stub carries the `#[Point]` attribute, an empty constructor, a `control()` with a commented `recover()` and `ensure()` and an `escalate()` that reports the exception, a `context()` returning an empty array, and an empty `handle()`. The test stub already uses `Monitor::fake()`: one case asserting the point succeeds and one making it fail with a `RuntimeException` and asserting it escalates. Fill in the risks and the test grows one case per risk.

## In CI

Run the check on every pull request and upload the SARIF so findings appear inline:

```yaml
name: Control points

on:
  pull_request:

permissions:
  contents: read
  security-events: write

jobs:
  points:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - run: composer install --no-interaction --prefer-dist

      - name: Check control points
        run: php artisan monitor:points --check --format=sarif > monitor.sarif
        continue-on-error: true
        id: check

      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: monitor.sarif

      - name: Fail on errors
        if: steps.check.outcome == 'failure'
        run: exit 1
```

The check step is allowed to fail so the upload still happens; the last step fails the job afterwards.
