<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use JsonException;

final readonly class Response
{
    /** @param array<string, mixed>|null $payload @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public ?array $payload = null,
        public array $headers = [],
        public ?string $body = null,
    ) {
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, $payload, $headers);
    }

    /** A non-JSON download (statement CSV/PDF). The headers must name the Content-Type. @param array<string, string> $headers */
    public static function file(string $body, array $headers): self
    {
        return new self(200, null, $headers, $body);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->payload, [...$this->headers, ...$headers], $this->body);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }
        if ($this->payload !== null) {
            header('Content-Type: application/json; charset=utf-8', true);
            try {
                echo json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                echo '{"error":{"code":"INTERNAL_ERROR","message":"Response encoding failed."}}';
            }
        } elseif ($this->body !== null) {
            echo $this->body;
        }
        exit;
    }
}

