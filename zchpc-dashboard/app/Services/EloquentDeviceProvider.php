<?php

namespace App\Services;

use App\Models\Terminal;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\DeviceProviderInterface;

/**
 * Eloquent-backed device provider.
 *
 * The built-in DatabaseDeviceProvider uses DB::table() which bypasses
 * Eloquent model casts. Because Terminal::$casts marks 'password' as
 * 'encrypted', the raw DB value is the Laravel cipher-text — not the
 * real password. This provider queries through the Eloquent model so
 * every cast (including password decryption) is applied before the
 * config is handed to HikvisionClient.
 */
class EloquentDeviceProvider implements DeviceProviderInterface
{
    /** @var array<string, array> In-memory cache keyed by terminal name */
    private array $cache = [];

    private bool $loaded = false;

    public function getDeviceNames(): array
    {
        $this->load();

        return array_keys($this->cache);
    }

    public function getDeviceConfig(string $deviceName): ?array
    {
        $this->load();

        return $this->cache[$deviceName] ?? null;
    }

    public function getDefaultDevice(): ?string
    {
        $this->load();

        $names = array_keys($this->cache);

        return $names[0] ?? null;
    }

    public function hasDevice(string $deviceName): bool
    {
        $this->load();

        return isset($this->cache[$deviceName]);
    }

    public function getGlobalConfig(): array
    {
        return [
            'format' => 'json',
            'logging' => [
                'enabled' => true,
                'channel' => 'stack',
            ],
        ];
    }

    /**
     * Clear in-memory cache so the next call re-reads from the DB.
     * Called by TerminalController after create / update / delete.
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->loaded = false;
    }

    // -------------------------------------------------------------------------

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        // Eloquent::all() applies every model cast, so 'password' is
        // automatically decrypted before we place it in $this->cache.
        // Standalone/Wiegand readers are intentionally excluded: they do not
        // implement the Hikvision ISAPI contract and must never receive ISAPI
        // requests from this application.
        foreach (Terminal::apiManaged()->get() as $terminal) {
            $this->cache[$terminal->name] = [
                'ip' => $terminal->ip,
                'port' => (int) $terminal->port,
                'username' => $terminal->username,
                'password' => $terminal->password,   // decrypted by cast
                'protocol' => $terminal->protocol,
                'timeout' => (int) $terminal->timeout,
                'verify_ssl' => (bool) $terminal->verify_ssl,
            ];
        }

        $this->loaded = true;
    }
}
