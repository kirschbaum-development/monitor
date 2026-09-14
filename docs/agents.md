# Agents

- [Introduction](#introduction)
- [The Guidelines](#the-guidelines)
- [The MCP Server](#the-mcp-server)
    - [Enabling It](#enabling-it)
    - [Tools](#tools)
    - [Resources](#resources)
    - [The wrap_operation Prompt](#the-wrap_operation-prompt)
    - [Redaction](#redaction)
    - [Connecting an Agent](#connecting-an-agent)

## Introduction

An agent that adds a critical operation should not need the conventions spelled out in a prompt. The package carries them two ways: a guidelines file that Laravel Boost composes into the application's agent guidelines, so the agent knows the convention before it writes anything, and an MCP server that lets the agent read the inventory and recent outcomes, so it can find out what a point does and what happened at it instead of grepping logs.

## The Guidelines

The package ships `.ai/guidelines/core.blade.php`. [Laravel Boost](https://github.com/laravel/boost) discovers guidelines in every installed package's `.ai/guidelines/` directory and composes them, with the project's own, into the single guidelines file it writes for the agent when `boost:install` runs. Requiring the package is enough for the convention to reach every agent working in the application.

The file tells an agent:

- what makes an operation critical, and that in doubt it is;
- that a critical operation is a class under `App\ControlPoints\{Domain}`, created with `make:control-point`, with a worked example;
- the rules `monitor:points --check` enforces: dotted lowercase unique names, an escalation on every point, a `control()` that does not read constructor arguments, which profile fits which kind of operation;
- that expected failures are declared with `recover()` and its return value is the result, rather than caught around the call;
- what `within()`, `attempts()` and `ensure()` do;
- how to test a point with `Monitor::fake()` and its assertions;
- how to read what happened with `monitor:points`, `monitor:explain` and `monitor:outcomes`.

To adjust the wording for one application, add a guideline under the project's own `.ai/guidelines/` directory; Boost's precedence rules let a project guideline stand in for a package's, so consult the Boost documentation for the exact path it expects.

## The MCP Server

`Kirschbaum\Monitor\Mcp\MonitorServer` is a read-only server built on [`laravel/mcp`](https://github.com/laravel/mcp). It is off by default and does nothing unless `laravel/mcp` is installed.

### Enabling It

```bash
composer require laravel/mcp
```

```php
'mcp' => [
    'enabled' => env('MONITOR_MCP', false),
    'handle' => 'monitor',
],
```

With `MONITOR_MCP=true`, the service provider registers the server as a local server under the handle, and it starts over stdio with:

```bash
php artisan mcp:start monitor
```

The server's instructions tell the client what a control point is and which tool to reach for.

### Tools

| Tool | Arguments | Returns |
| --- | --- | --- |
| `list_points` | `domain` (optional) | Every control point as the inventory describes it, plus the findings. With `domain`, only that domain's points and the findings about them. JSON. |
| `explain_point` | `point` (required) | The point's contract in prose, the same text as `monitor:explain`, with its last 24 hours when the store is on. An error when the name is unknown. |
| `outcomes` | `point`, `domain`, `status`, `trace`, `since` (`15m`, `2h`, `7d`; default `24h`), `limit` (default 50, max 500), all optional | Recent outcomes newest first, with `since`, `count` and the rows. JSON. Requires the [outcome store](store.md). |
| `escalations` | `since` (default `24h`), `domain`, both optional | Runs that escalated or were refused in the window, grouped by point with counts, the exception classes seen, and the last time and trace id. JSON. Requires the store. |

The two store-backed tools return an error saying so while the store is disabled; records are still in the log.

### Resources

| URI | Content |
| --- | --- |
| `monitor://schema/record-1` | The JSON schema of every record, `resources/schema/record-1.json`, as `application/json`. |
| `monitor://guidelines` | The guidelines file, as `text/markdown`. An agent can read the convention without a tool call. |

### The wrap_operation Prompt

`wrap_operation` takes `path`, a PHP file relative to the project root, and an optional `point` name. It returns a user message that asks the model to turn the operation in that file into a control point class: infer the risks from the existing `catch` blocks, pick the profile from what the operation does, add an `ensure()` if the call can succeed with a failing result, declare an escalation, and write the Pest test with one case per risk and one for an unexpected failure. The guidelines and the file's source are included in the message.

### Redaction

Every response passes through Kirschbaum Redactor with the profile in `records.redaction`, the same one records use. The server's redactor knows its tools answer in JSON: a JSON text block is decoded and redacted field by field, so a trace id or a ULID is not mistaken for a secret and a whole payload is never replaced for being long. Prose, such as an explanation or the prompt, is redacted line by line. Error messages are redacted too. Set `records.redaction` to null to turn it off.

### Connecting an Agent

A local stdio server, for Claude Code:

```json
{
  "mcpServers": {
    "monitor": {
      "command": "php",
      "args": ["artisan", "mcp:start", "monitor"],
      "cwd": "/path/to/the/application"
    }
  }
}
```

Cursor and other clients take the same three values: the command `php`, the arguments `artisan mcp:start monitor`, and the application root as the working directory. `MONITOR_MCP=true` must be set in the environment the command runs in.
