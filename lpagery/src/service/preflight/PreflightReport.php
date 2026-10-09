<?php

namespace LPagery\service\preflight;

use JsonSerializable;

/**
 * Everything one Pre-flight Check run found, in check order.
 */
class PreflightReport implements JsonSerializable
{
    /** @var Finding[] */
    private array $findings;

    /**
     * @param Finding[] $findings
     */
    public function __construct(array $findings)
    {
        $this->findings = $findings;
    }

    /**
     * @return Finding[]
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function jsonSerialize(): array
    {
        return [
            'findings' => array_map(function (Finding $finding) {
                return $finding->jsonSerialize();
            }, $this->findings),
        ];
    }
}
