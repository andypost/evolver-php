<?php

declare(strict_types=1);

namespace DrupalEvolver\Adapter;

final class AdapterRegistry
{
    /** @var array<string, EcosystemAdapterInterface> */
    private array $adapters = [];

    public function __construct()
    {
        $this->register(new DrupalCoreAdapter());
        $this->register(new SymfonyAdapter());
    }

    public function register(EcosystemAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->ecosystem()] = $adapter;
    }

    public function get(string $ecosystem): ?EcosystemAdapterInterface
    {
        return $this->adapters[$ecosystem] ?? null;
    }

    public function detectForProject(string $projectPath): ?EcosystemAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            $type = $adapter->detectProjectType($projectPath);
            if ($type !== null) {
                return $adapter;
            }
        }
        return null;
    }

    /**
     * Get the default adapter (Drupal for now).
     */
    public function getDefault(): EcosystemAdapterInterface
    {
        return $this->adapters['drupal'] ?? throw new \RuntimeException('Default Drupal adapter not registered.');
    }
}
