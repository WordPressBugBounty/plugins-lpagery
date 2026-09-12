<?php

namespace LPagery\service\image_endpoint;

/**
 * The terminal branch a single {@see EdgeCacheProbe::run()} produced, for the Phase-4 scheduling layer to
 * react to. PHP 7.4 has no enums, so this is an int-backed const set — a tiny value carrier, never
 * instantiated. {@see self::RETRY} is the only outcome that warrants scheduling another run; the other two
 * are terminal (the probe is permanently done, or has nothing left to do).
 */
final class EdgeCacheProbeResult
{
    /** A conclusive verdict was written and the setting is decided — probing ends permanently. */
    public const CONCLUSIVE = 1;
    /** Inconclusive and still under the attempt cap — the scheduler should retry later. */
    public const RETRY = 2;
    /** Nothing to do: the setting already carries a value, or the attempt cap is spent. No reschedule. */
    public const DORMANT = 3;
}
