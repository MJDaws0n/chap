<?php

namespace Chap\Controllers\Api;

use Chap\Controllers\BaseController;

/**
 * Base API Controller
 * 
 * Common functionality for all API controllers
 */
abstract class BaseApiController extends BaseController
{
    /**
     * API controllers always return JSON
     */
    protected function isApiRequest(): bool
    {
        return true;
    }

    /**
     * Return success response
     */
    protected function success(array $data = [], int $status = 200): void
    {
        $this->json($data, $status);
    }

    /**
     * Return error response
     */
    protected function error(string $message, int $status = 400): void
    {
        $this->json(['error' => $message], $status);
    }

    /**
     * Return not found response
     */
    protected function notFound(string $message = 'Resource not found'): void
    {
        $this->error($message, 404);
    }

    /**
     * Return unauthorized response
     */
    protected function unauthorized(string $message = 'Unauthorized'): void
    {
        $this->error($message, 401);
    }

    /**
     * Return forbidden response
     */
    protected function forbidden(string $message = 'Forbidden'): void
    {
        $this->error($message, 403);
    }

    /**
     * Return validation error response
     */
    protected function validationError(array $errors): void
    {
        $this->json(['errors' => $errors], 422);
    }
}
