<?php

namespace PawPayments\Sdk\Exception;

class PawPaymentsApiException extends \RuntimeException
{
    private ?string $errorCode;
    private ?int $httpStatus;

    public function __construct(
        string $message = '',
        ?string $errorCode = null,
        ?int $httpStatus = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
