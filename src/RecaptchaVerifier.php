<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks Google's siteverify endpoint about a token.
 *
 * @see https://developers.google.com/recaptcha/docs/verify
 */
final readonly class RecaptchaVerifier
{
    public const string ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $secretKey,
        private float $timeout = 5.0,
    ) {
    }

    /**
     * @throws RecaptchaUnavailableException when Google could not be asked
     */
    public function verify(string $token, ?string $ip = null): RecaptchaVerification
    {
        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'body' => array_filter(['secret' => $this->secretKey, 'response' => $token, 'remoteip' => $ip]),
                'timeout' => $this->timeout,
            ]);

            return RecaptchaVerification::fromArray($response->toArray());
        } catch (ExceptionInterface $exception) {
            throw new RecaptchaUnavailableException('reCAPTCHA could not be verified: '.$exception->getMessage(), previous: $exception);
        }
    }
}
