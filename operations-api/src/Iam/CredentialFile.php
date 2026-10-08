<?php

declare(strict_types=1);

namespace Arasya\Operations\Iam;

use RuntimeException;

/**
 * A private, printable credential sheet for the administrator: a new file per run in a 0700 directory outside
 * the web root, created exclusively with mode 0600, one page per person (form feed between pages). Each entry
 * is flushed to disk before the next account is changed, so an interrupted run never loses a credential it
 * already issued. Delete the file once every sheet has been handed over.
 */
final class CredentialFile implements CredentialSink
{
    private const APPLICATION_LABELS = [
        'staff' => 'Staff — https://staff.arasyahome.ro',
        'dashboard' => 'Dashboard — https://dashboard.arasyahome.ro',
        'b2b' => 'B2B — https://b2b.arasyahome.ro',
    ];

    /** @var resource|null */
    private $handle = null;
    private string $path;
    private int $count = 0;

    /** @param list<string> $forbiddenRoots directories the file must never be written into (release, web roots) */
    public function __construct(string $directory, array $forbiddenRoots, \DateTimeImmutable $now)
    {
        if ($directory === '' || !str_starts_with($directory, '/')) {
            throw new RuntimeException('The credential directory must be an absolute path.');
        }
        $previous = umask(0077);
        try {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('The credential directory could not be created.');
            }
            chmod($directory, 0700);
            $real = realpath($directory);
            if ($real === false) {
                throw new RuntimeException('The credential directory is not usable.');
            }
            foreach ($forbiddenRoots as $root) {
                $forbidden = realpath($root);
                if ($forbidden !== false && ($real === $forbidden || str_starts_with($real . '/', rtrim($forbidden, '/') . '/'))) {
                    throw new RuntimeException('Credentials are never written inside the application release or a web root.');
                }
            }
            if (str_contains($real . '/', '/public_html/')) {
                throw new RuntimeException('Credentials are never written inside a web root.');
            }
            $this->path = $real . '/credentials-' . $now->format('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.txt';
        } finally {
            umask($previous);
        }
    }

    public function write(array $credential): void
    {
        if ($this->handle === null) {
            $previous = umask(0077);
            $handle = fopen($this->path, 'x');
            umask($previous);
            if ($handle === false) {
                throw new RuntimeException('The credential file could not be created.');
            }
            chmod($this->path, 0600);
            $this->handle = $handle;
        }
        $applications = array_map(static fn (string $key): string => self::APPLICATION_LABELS[$key] ?? $key, $credential['applications']);
        $page = ($this->count > 0 ? "\f" : '') . implode("\n", [
            'ARASYA HOME — DATE DE AUTENTIFICARE PERSONALE (CONFIDENȚIAL)',
            str_repeat('=', 62),
            'Nume:              ' . $credential['name'],
            'Utilizator:        ' . $credential['username'],
            'Parolă temporară:  ' . $credential['temporaryPassword'],
            'Aplicații:         ' . ($applications === [] ? '—' : implode("\n                   ", $applications)),
            'Motiv:             ' . $credential['reason'],
            '',
            '1. Deschide aplicația și autentifică-te cu utilizatorul și parola temporară de mai sus.',
            '2. Sistemul îți cere imediat să alegi o parolă personală: minimum 12 caractere,',
            '   diferită de parola temporară și fără numele de utilizator.',
            '3. Aceeași parolă personală funcționează în toate aplicațiile Arasya la care ai acces.',
            '4. Nu comunica parola nimănui. Dacă ai uitat-o, cere resetarea administratorului.',
            '',
        ]) . "\n";
        if (fwrite($this->handle, $page) !== strlen($page) || !fflush($this->handle) || !fsync($this->handle)) {
            throw new RuntimeException('The credential file could not be written.');
        }
        $this->count++;
    }

    public function location(): string
    {
        return $this->count === 0 ? '(no credential issued, no file written)' : $this->path;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}
