<?php

namespace Chap\Services\ChapScript;

final class ChapScriptValidationException extends \RuntimeException
{
    /** @var array<int,string> */
    private array $errors;

    /**
     * @param array<int,string> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct($errors[0] ?? 'Invalid ChapScript');
    }

    /** @return array<int,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
