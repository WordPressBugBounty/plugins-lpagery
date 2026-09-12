<?php

namespace LPagery\multilingual;

/**
 * Picks the one Multilingual Plugin LPagery talks to for this request.
 *
 * The adapter list is plain construction (same shape as the pagebuilder registry): no DI
 * container, no filter-based registration. Order is the precedence order, so WPML wins when a
 * site somehow has two Multilingual Plugins active at once; that conflict is logged once per
 * request. The result is memoized, so each adapter's detection runs at most once.
 */
class MultilingualPluginResolver
{
    /** @var MultilingualPlugin[] */
    private array $adapters;

    private bool $resolved = false;

    private ?MultilingualPlugin $plugin = null;

    /**
     * @param MultilingualPlugin[]|null $adapters Defaults to the built-in adapters, in precedence order.
     */
    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? array(new WpmlAdapter(), new PolylangAdapter());
    }

    /** The single active Multilingual Plugin, or null when none is active. */
    public function resolve(): ?MultilingualPlugin
    {
        if ($this->resolved) {
            return $this->plugin;
        }
        $this->resolved = true;

        $active = array();
        foreach ($this->adapters as $adapter) {
            if ($adapter->is_active()) {
                $active[] = $adapter;
            }
        }

        if (count($active) > 1) {
            $keys = array();
            foreach ($active as $adapter) {
                $keys[] = $adapter->plugin_key();
            }
            error_log('LPagery: more than one Multilingual Plugin is active (' . implode(', ',
                    $keys) . '); using ' . $active[0]->plugin_key() . '.');
        }

        $this->plugin = $active[0] ?? null;
        return $this->plugin;
    }

    /**
     * The adapters this resolver considers, in precedence order.
     *
     * @return MultilingualPlugin[]
     */
    public function get_adapters(): array
    {
        return $this->adapters;
    }
}
