# Jobs

- [Introduction](#introduction)
- [What a Job Inherits](#what-a-job-inherits)
- [Running a Point on the Queue](#running-a-point-on-the-queue)
    - [RunControlPoint](#runcontrolpoint)
    - [Horizon Tags](#horizon-tags)
    - [When the Breaker Is Open](#when-the-breaker-is-open)
- [Waiting for a Breaker](#waiting-for-a-breaker)
- [Why the Queue Keeps Its Retries](#why-the-queue-keeps-its-retries)
- [The Store in a Worker](#the-store-in-a-worker)

## Introduction

Most critical operations run on the queue. Monitor does not replace the queue's own retry, backoff and failure handling; it runs inside a job, or as one, and leaves the job's lifecycle to Laravel. This page covers what a job inherits from the process that dispatched it, how to run a control point class as a job, and how to keep a job from retrying into a dependency whose circuit is open.

## What a Job Inherits

Laravel's Context is serialised with a job and restored when the job runs. Two things travel that way:

- **The trace id**, so every record the job writes shares the id of the request that queued it. A job dispatched from a process with no trace gets one of its own when it starts.
- **The dispatching run**, not the stack. A job dispatched from inside `order.place` would otherwise start with `order.place` on its stack and report a `parent_run_id` for a run that ended in another process. Monitor listens to `Illuminate\Queue\Events\JobProcessing` with `Kirschbaum\Monitor\Trace\PicksUpJobTrace`, which picks up the trace and then calls `ControlStack::handOff()`: the inherited stack is cleared and the innermost run id is kept in Context as `dispatched_from_run`.

So a point run inside a job has no parent, its `stack` holds only itself, and every log line the job writes, Monitor's records and the application's own, carries `dispatched_from_run` alongside `trace_id`. `Monitor::stack()->dispatchedFrom()` returns it in code.

## Running a Point on the Queue

A control point class can be dispatched like a job:

```php
use App\ControlPoints\Payments\ChargeCard;

ChargeCard::dispatch($invoice, $amount);                    // queued
ChargeCard::dispatch($invoice, $amount)->onQueue('payments');
ChargeCard::dispatchSync($invoice, $amount);                // through the queue, now
```

`dispatch(...$arguments)` constructs the point with the arguments, wraps it in `Kirschbaum\Monitor\Queue\RunControlPoint` and returns Laravel's `PendingDispatch`, so `onQueue()`, `onConnection()`, `delay()` and `afterCommit()` work as they do for any job. `dispatchSync(...$arguments)` runs it through the queue synchronously and returns what the job returned.

### RunControlPoint

`Kirschbaum\Monitor\Queue\RunControlPoint` is a queued job with two public properties: `point`, the `ControlPoint` instance, and `releaseWhenRefused`, which is `true` by default. Its `handle()` executes the point:

| The run | The job |
| --- | --- |
| Succeeded or recovered | Completes normally and returns the `Outcome`. |
| Escalated | Throws the exception that escaped, so the job fails the way a job fails: `failed_jobs`, the job's `failed()` method and the queue's retries all see exactly what the point saw. |
| Refused by an open breaker | Releases the job for the breaker's retry-after instead of failing it. With `releaseWhenRefused` set to `false` it throws `BreakerOpen` instead. |

The point keeps its policies, corrections, limits, escalation and records; the job keeps its queue, connection, tries and backoff. Nothing about the point changes because it ran on a worker.

### Horizon Tags

`displayName()` is the point name, so the queue and Horizon show `payment.charge` rather than the wrapper class. `tags()` returns `monitor:{point}` and `domain:{domain}`, so Horizon groups failures by operation and by domain.

### When the Breaker Is Open

A refused run does not fail the job. `RunControlPoint` calls `release()` with the seconds left on the circuit, so the job comes back when the breaker may let a probe through rather than on the queue's own backoff. Every release counts as an attempt, so the job's `$tries` or `retryUntil()` still bounds how long it waits.

## Waiting for a Breaker

A job that is not a control point can wait for a circuit the same way, with the `Kirschbaum\Monitor\Queue\Middleware\WaitForBreaker` job middleware:

```php
use Kirschbaum\Monitor\Queue\Middleware\WaitForBreaker;

class SyncToCrm implements ShouldQueue
{
    public function middleware(): array
    {
        return [new WaitForBreaker('crm')];
    }
}
```

While the `crm` circuit is open the middleware releases the job for the breaker's retry-after, at least `minimumDelay` seconds, and does not run it; when the circuit is closed or due for a probe the job runs. `new WaitForBreaker('crm', minimumDelay: 30)` raises the floor. As with `RunControlPoint`, each release is an attempt.

The middleware reads the circuit through `isOpen()` and never consumes the half-open probe; the first control point or `Http::breaker()` call through does that.

## Why the Queue Keeps Its Retries

A job's `$tries`, `backoff()`, `retryUntil()`, `failed()` and the failed-jobs table are a complete retry and escalation story for the job as a whole. A control point's `Retry` policy retries the operation inside one attempt of the job; the queue retries the job. Do not wrap a job's `handle()` in a point to get retries, and do not give a point inside a job a `Retry` policy that duplicates the job's backoff. Wrap the critical operation so its outcome is recorded and its breaker is consulted, and let the queue own the job.

## The Store in a Worker

The [outcome store](store.md) buffers rows and writes them after each job (`JobProcessed` and `JobExceptionOccurred`), on `Illuminate\Queue\Events\Looping`, on `WorkerStopping`, and whenever the buffer reaches one hundred outcomes, so a long-running worker never holds an unbounded buffer and a stopped worker writes what it had.
