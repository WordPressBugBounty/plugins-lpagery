<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * One check of the Pre-flight Check. Checks run independently: one that throws is reported as a
 * single "Couldn't check …" Problem named by {@see id()}, and the others still run.
 */
interface PreflightCheck
{
    /**
     * Stable id, sent with the Problem when the check fails.
     */
    public function id(): string;

    /**
     * @return Finding[]
     */
    public function run(PreflightContext $context): array;
}
