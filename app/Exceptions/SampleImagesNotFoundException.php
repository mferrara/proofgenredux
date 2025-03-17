<?php

namespace App\Exceptions;

use Exception;

class SampleImagesNotFoundException extends Exception
{
    public function __construct(string $message = "Sample images not found", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}