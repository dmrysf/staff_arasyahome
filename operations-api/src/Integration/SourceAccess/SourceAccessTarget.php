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
    public const FINANCE_OFF = 'off';
    public const FINANCE_READ = 'read';
    public const FINANCE_RECORD = 'record';
    public const INVOICE_OFF = 'off';
    public const INVOICE_OBSERVE = 'observe';
    public const INVOICE_ENFORCE = 'enforce';

    public function __construct(
        public string $sourceKey,
        public string $mode,
        /** The shop's base URL: scheme, host and optional port only (no path, query, credentials or fragment). */
        public string $baseUrl,
        public string $kid,
        #[\SensitiveParameter] private string $key,
        /** Confirmare finance through this shop (API 2.41.0): off, read (the financial summary) or record (also actions). */
        public string $finance = self::FINANCE_OFF,
        /** SmartBill invoicing through this shop (API 2.43.0): off, observe (the state only) or enforce (also actions). */
        public string $invoice = self::INVOICE_OFF,
    ) {
    }

    public function allowsInvoiceRead(): bool
    {
        return $this->invoice === self::INVOICE_OBSERVE || $this->invoice === self::INVOICE_ENFORCE;
    }

    public function allowsInvoiceActions(): bool
    {
        return $this->invoice === self::INVOICE_ENFORCE;
    }

    public function allowsFinanceRead(): bool
    {
        return $this->finance === self::FINANCE_READ || $this->finance === self::FINANCE_RECORD;
    }

    public function allowsFinanceRecord(): bool
    {
        return $this->finance === self::FINANCE_RECORD;
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
        return ['sourceKey' => $this->sourceKey, 'mode' => $this->mode, 'baseUrl' => $this->baseUrl, 'kid' => $this->kid, 'finance' => $this->finance, 'invoice' => $this->invoice];
    }
}
