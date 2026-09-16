<?php
namespace App\Service;

/**
 * Registry des Storage Zones Bunny configurées.
 *
 * Une Storage Zone Bunny = un bucket avec son propre AccessKey et son propre
 * domaine CDN public. Plutôt que de figer le code sur une seule zone, ce
 * registry instancie un BunnyStorageService par zone à la demande et mémoïze
 * les instances pour la durée de la requête.
 */
class BunnyZoneRegistry
{
    /** @var array<string, BunnyStorageService> */
    private array $instances = [];

    /**
     * @param array<string, array{accessKey:string, cdnBaseUrl:string, endpoint?:string}> $zones
     *        Map indexée par nom de zone (ex: "cinaftv-movies"). `endpoint` est
     *        optionnel : il n'est nécessaire que pour une zone hébergée hors de
     *        la région par défaut (ex. Stockholm → `se.storage.bunnycdn.com`).
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $caBundle,
        private readonly array $zones,
        private readonly string $defaultZone,
    ) {
        if (!isset($this->zones[$this->defaultZone])) {
            throw new \InvalidArgumentException(
                "Bunny default zone '{$this->defaultZone}' is not declared in app.bunny.zones."
            );
        }
    }

    /** @return list<string> */
    public function getZoneNames(): array
    {
        $names = array_keys($this->zones);
        sort($names);
        return $names;
    }

    public function getDefaultZoneName(): string
    {
        return $this->defaultZone;
    }

    public function has(string $name): bool
    {
        return isset($this->zones[$name]);
    }

    /**
     * Résout le service Bunny Storage pour une zone donnée.
     * Si $name est null ou vide, utilise la zone par défaut.
     *
     * @throws \InvalidArgumentException si la zone est inconnue
     */
    public function get(?string $name = null): BunnyStorageService
    {
        $name = $name === null || $name === '' ? $this->defaultZone : $name;

        if (!isset($this->zones[$name])) {
            throw new \InvalidArgumentException(
                "Bunny storage zone '$name' is not configured. Available: "
                . implode(', ', $this->getZoneNames())
            );
        }

        return $this->instances[$name] ??= new BunnyStorageService(
            // Endpoint propre à la zone si elle est dans une autre région,
            // sinon l'endpoint global.
            endpoint: $this->zones[$name]['endpoint'] ?? $this->endpoint,
            storageZone: $name,
            accessKey: $this->zones[$name]['accessKey'],
            bunnyCdnBaseUrl: $this->zones[$name]['cdnBaseUrl'] ?? '',
            caBundle: $this->caBundle,
        );
    }
}
