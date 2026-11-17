<?php

declare(strict_types=1);

namespace Dolifleet\Inventory;

/**
 * An instance of the inventory, with the settings inherited from its server.
 */
final class Instance
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $server,
        public readonly string $htdocs,
        public readonly string $runAs,
        public readonly string $php,
        public readonly string $webPhp,
        public readonly string $dolibarrUser,
        public readonly string $maintenanceLogin,
        public readonly array $tags,
        public readonly string $source,
    ) {
    }

    /**
     * Stand-in for commands that work on a server rather than an instance (discover, setup).
     */
    public static function forServer(string $server, string $php): self
    {
        return new self('server-'.mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $server)), $server, '/', '', $php, '', '', '', [], 'server');
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * @param list<string> $protectedTags
     */
    public function isProtected(array $protectedTags): bool
    {
        return [] !== array_intersect($protectedTags, $this->tags);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'server' => $this->server,
            'htdocs' => $this->htdocs,
            'run_as' => $this->runAs,
            'php' => $this->php,
            'web_php' => $this->webPhp,
            'dolibarr_user' => $this->dolibarrUser,
            'maintenance_login' => $this->maintenanceLogin,
            'tags' => $this->tags,
        ];
    }
}
