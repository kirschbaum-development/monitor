<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp;

use Generator;
use Kirschbaum\Redactor\Facades\Redactor;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Redacts what the Monitor server returns, knowing that its tools answer in
 * JSON: a JSON text block is decoded and redacted field by field, so a trace
 * id or a ULID is not mistaken for a secret and a whole payload is never
 * replaced because it was long. Prose is redacted line by line for the same
 * reason. Resources are package files and pass through untouched.
 */
final readonly class ResponseRedactor
{
    public function __construct(private ?string $profile) {}

    /**
     * @param  iterable<JsonRpcResponse>|JsonRpcResponse  $response
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    public function redact(iterable|JsonRpcResponse $response): iterable|JsonRpcResponse
    {
        if ($this->profile === null) {
            return $response;
        }

        if ($response instanceof JsonRpcResponse) {
            return $this->one($response);
        }

        return $this->each($response);
    }

    /**
     * @param  iterable<JsonRpcResponse>  $responses
     * @return Generator<JsonRpcResponse>
     */
    private function each(iterable $responses): Generator
    {
        foreach ($responses as $response) {
            yield $this->one($response);
        }
    }

    private function one(JsonRpcResponse $response): JsonRpcResponse
    {
        $content = $response->content;

        if (isset($content['result']) && is_array($content['result'])) {
            $content['result'] = $this->result($content['result']);
        }

        if (isset($content['params']) && is_array($content['params']) && isset($content['params']['content'])) {
            $content['params'] = $this->result($content['params']);
        }

        if (isset($content['error']) && is_array($content['error']) && is_string($content['error']['message'] ?? null)) {
            $content['error']['message'] = $this->text($content['error']['message']);
        }

        $response->content = $content;

        return $response;
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>
     */
    private function result(array $result): array
    {
        if (isset($result['content']) && is_array($result['content'])) {
            $result['content'] = array_map($this->item(...), $result['content']);
        }

        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            $result['structuredContent'] = $this->data($result['structuredContent']);
        }

        if (isset($result['messages']) && is_array($result['messages'])) {
            $result['messages'] = array_map(function (mixed $message): mixed {
                if (is_array($message) && isset($message['content'])) {
                    $message['content'] = is_array($message['content']) ? $this->item($message['content']) : (is_string($message['content']) ? $this->text($message['content']) : $message['content']);
                }

                return $message;
            }, $result['messages']);
        }

        return $result;
    }

    private function item(mixed $item): mixed
    {
        if (is_array($item) && is_string($item['text'] ?? null)) {
            $item['text'] = $this->text($item['text']);
        }

        return $item;
    }

    public function text(string $text): string
    {
        if ($this->profile === null) {
            return $text;
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded) && $decoded !== []) {
            return (string) json_encode($this->data($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $lines = array_map(function (string $line): string {
            $out = Redactor::redactSafely($line, $this->profile);

            return is_string($out) ? $out : (string) json_encode($out);
        }, explode("\n", $text));

        return implode("\n", $lines);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function data(array $data): array
    {
        if ($this->profile === null) {
            return $data;
        }

        try {
            $out = Redactor::inspect($data, $this->profile, mark: false)->value;
        } catch (Throwable) {
            return ['redaction' => 'failed'];
        }

        return is_array($out) ? $out : ['redaction' => 'failed'];
    }
}
