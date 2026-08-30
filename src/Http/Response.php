<?php

declare(strict_types=1);

namespace RosinTracker\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?string $filePath = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string, string> $headers */
    public static function file(string $path, array $headers): self
    {
        return new self('', 200, $headers, $path);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->filePath !== null) {
            $size = filesize($this->filePath);
            if ($size !== false && !isset($this->headers['Content-Length'])) {
                header('Content-Length: ' . $size);
            }
            $stream = fopen($this->filePath, 'rb');
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }
            exit;
        }
        echo $this->body;
        exit;
    }
}
