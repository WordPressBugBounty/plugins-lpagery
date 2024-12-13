<?php
namespace LPagery\model;
namespace LPagery\model;

class TrackingPermissions implements \JsonSerializable
{
    private bool $sentry;
    private bool $posthog;
    private bool $intercom;

    public function __construct(bool $sentry, bool $posthog, bool $intercom)
    {
        $this->sentry = $sentry;
        $this->posthog = $posthog;
        $this->intercom = $intercom;
    }

    public function getSentry(): bool
    {
        return $this->sentry;
    }

    public function getPosthog(): bool
    {
        return $this->posthog;
    }

    public function getIntercom(): bool
    {
        return $this->intercom;
    }

    public function jsonSerialize(): array
    {
        return [
            'sentry' => $this->sentry,
            'posthog' => $this->posthog,
            'intercom' => $this->intercom,
        ];
    }
}
