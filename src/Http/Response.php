<?php

declare(strict_types=1);

namespace Viagem\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = ['Content-Type' => 'text/plain; charset=utf-8'],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function error(string $message, int $status): self
    {
        return self::json(['error' => $message], $status);
    }

    public static function tile(string $data): self
    {
        if ($data === '') {
            return new self(204, '', ['Content-Type' => 'application/vnd.mapbox-vector-tile']);
        }
        return new self(200, $data, ['Content-Type' => 'application/vnd.mapbox-vector-tile']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
