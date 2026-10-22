<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

/**
 * Where and how Operations may reach one shop's source access endpoint (configured, validated by
 * SourceAccessRegistry). The key is held for signing only: it never appears in a dump, a log, an error or a response.
 */
final readonly class SourceAccessTarget
{
    public const MODE_READ = 'read';
    public const MODE_EDIT = 'edit';

    public function __construct(
        public string $sourceKey,
        public string $mode,
        /** The shop's base URL: scheme, host and optional port only (no path, query, credentials or fragment). */
        public string $baseUrl,
        public string $kid,
        #[\SensitiveParameter] private string $key,
    ) {
    }

    public function allowsEdit(): bool
    {
        return $this->mode === self::MODE_EDIT;
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['sourceKey' => $this->sourceKey, 'mode' => $this->mode, 'baseUrl' => $this->baseUrl, 'kid' => $this->kid];
    }
}
