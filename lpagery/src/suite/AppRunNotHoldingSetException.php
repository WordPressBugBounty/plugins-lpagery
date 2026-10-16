<?php

namespace LPagery\suite;

use Exception;

/**
 * Thrown when the LPagery App sends a page after the first of an App Run that no longer holds the Page
 * Set (issue #309): a page from another run, or a late retry from a run that finished, was aborted or
 * was Abandoned. The page is refused and nothing is created.
 */
class AppRunNotHoldingSetException extends Exception
{
    public const MESSAGE = 'This page belongs to an LPagery App run that no longer holds this page set, so LPagery did not create it.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
