<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Kirschbaum\Monitor\Facades\Monitor;
use Throwable;

final class Ccp
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function run(string $name, Closure $callback, array $context = [], ?Closure $onFail = null): mixed
    {
        $trace = Monitor::trace()->pickup();

        $ccpTimer = new LogTimer;

        $ccpId = Str::uuid()->toString();

        Monitor::log("CCP:$name")->info('STARTED', array_merge($context, [
            'ccp' => $name,
            'ccp_id' => $ccpId,
            'trace_id' => $trace->id(),
        ]));

        try {
            $result = $callback();

            Monitor::log("CCP:$name")->info('ENDED', array_merge($context, [
                'ccp' => $name,
                'ccp_id' => $ccpId,
                'trace_id' => $trace->id(),
                'duration_ms' => Monitor::time()->elapsed(),
                'ccp_duration_ms' => $ccpTimer->elapsed(),
                'status' => 'ok',
            ]));

            return $result;
        } catch (Throwable $e) {
            $logContext = array_merge($context, [
                'ccp' => $name,
                'ccp_id' => $ccpId,
                'trace_id' => $trace->id(),
                'duration_ms' => Monitor::time()->elapsed(),
                'ccp_duration_ms' => $ccpTimer->elapsed(),
                'status' => 'failed',
            ]);

            if ($exception = self::flattenException($e)) {
                $logContext['exception'] = $exception;
            }

            Monitor::log("CCP:$name")->critical('FAILED', $logContext);

            // Execute failure callback if provided
            if ($onFail) {
                try {
                    $onFail($e, $logContext);
                } catch (Throwable $callbackException) {
                    // Log callback failure but don't let it interfere with original exception
                    Monitor::log("CCP:$name")->error('FAILURE_CALLBACK_ERROR', [
                        'ccp' => $name,
                        'ccp_id' => $ccpId,
                        'original_exception' => $e->getMessage(),
                        'callback_exception' => $callbackException->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private static function flattenException(Throwable $e): ?array
    {
        if (! Config::boolean('monitor.exception_trace.enabled', true)) {
            return null;
        }

        $isDebug = Config::boolean('app.debug') || Config::boolean('monitor.exception_trace.force_full_trace', false);
        $fullTrace = explode("\n", $e->getTraceAsString());

        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $isDebug && Config::boolean('monitor.exception_trace.full_on_debug', true)
                ? $fullTrace
                : array_slice($fullTrace, 0, Config::integer('monitor.exception_trace.max_lines', 15)),
        ];
    }
}
