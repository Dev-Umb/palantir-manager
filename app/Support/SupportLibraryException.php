<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportLibraryException extends HttpException
{
    public function __construct(string $message, public readonly string $errorCode = 'LIBRARY_INVALID', int $status = 422, public readonly array $context = [])
    {
        parent::__construct($status, $message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode, ...$this->context], $this->getStatusCode());
    }
}
