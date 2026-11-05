<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle;

/**
 * What Google said about a token.
 */
final readonly class RecaptchaVerification
{
    /**
     * @param list<string> $errorCodes
     */
    public function __construct(
        public bool $success,
        public ?float $score = null,
        public ?string $action = null,
        public ?string $hostname = null,
        public array $errorCodes = [],
    ) {
    }

    /**
     * @param array<mixed> $data the JSON body of siteverify
     */
    public static function fromArray(array $data): self
    {
        $codes = \is_array($data['error-codes'] ?? null) ? array_values(array_filter($data['error-codes'], 'is_string')) : [];

        return new self(
            success: ($data['success'] ?? false) === true,
            score: \is_int($data['score'] ?? null) || \is_float($data['score'] ?? null) ? (float) $data['score'] : null,
            action: \is_string($data['action'] ?? null) ? $data['action'] : null,
            hostname: \is_string($data['hostname'] ?? null) ? $data['hostname'] : null,
            errorCodes: $codes,
        );
    }

    /**
     * The token was valid once but has been used or has expired: the visitor can simply send again.
     */
    public function isExpired(): bool
    {
        return \in_array('timeout-or-duplicate', $this->errorCodes, true);
    }
}
