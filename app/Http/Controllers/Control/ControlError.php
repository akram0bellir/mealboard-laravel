<?php

namespace App\Http\Controllers\Control;

/** A refused control-panel action: sent to the page as {"status": ..., "message": ...}. */
class ControlError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
