<?php

namespace markhuot\craftai\agent\providers;

/**
 * Holds every configured {@see SearchProvider} so the `search_the_web` tool
 * can route a single call to whichever backend the agent (or the project
 * default) selects. The Plugin builds this once at boot from the
 * `searchProviders` config block — providers without config are absent here,
 * not silently constructed and broken at call time.
 */
class SearchProviderRegistry
{
    /** @var array<string, SearchProvider> */
    private readonly array $providers;

    private readonly ?string $defaultName;

    /**
     * @param  list<SearchProvider>  $providers  Order matters: when no default
     *         is configured and the tool call omits `provider`, the first
     *         entry wins. Mirrors the order keys appear in config.
     * @param  string|null  $defaultName  Explicit default override from config
     *         (`searchProviders.default`). Must match one of $providers' names
     *         or it's ignored.
     */
    public function __construct(array $providers, ?string $defaultName = null)
    {
        $map = [];
        foreach ($providers as $provider) {
            $map[$provider->name()] = $provider;
        }
        $this->providers = $map;

        if ($defaultName !== null && isset($map[$defaultName])) {
            $this->defaultName = $defaultName;
        } else {
            $this->defaultName = array_key_first($map);
        }
    }

    /**
     * Return the provider matching $name, or — when null — the configured
     * default. Throws when $name is given but no matching provider exists, so
     * the agent gets a clear error instead of silently falling back to a
     * different backend than it asked for.
     */
    public function get(?string $name = null): SearchProvider
    {
        if ($name === null || $name === '') {
            if ($this->defaultName === null) {
                throw new SearchException(
                    'No search providers are configured. Add at least one entry '
                    .'under `searchProviders` in config/craft-ai.php.',
                );
            }

            return $this->providers[$this->defaultName];
        }

        if (! isset($this->providers[$name])) {
            $available = implode(', ', array_keys($this->providers)) ?: '(none)';
            throw new SearchException(
                "Unknown search provider \"{$name}\". Configured: {$available}.",
            );
        }

        return $this->providers[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    public function defaultName(): ?string
    {
        return $this->defaultName;
    }
}
