<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\AsSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckResult;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Invisible reCAPTCHA v3: the visitor never sees a challenge. Google gives every submission a
 * score between 0 (a bot) and 1 (a person); below the threshold it is blocked, and lands in the
 * Spam tab of the form with its score.
 */
#[AsSpamCheck(
    key: self::KEY,
    title: 'eekes_form_builder_recaptcha.title',
    description: 'eekes_form_builder_recaptcha.description',
)]
final readonly class RecaptchaSpamCheck implements SpamCheckInterface
{
    public const string KEY = 'recaptcha';

    /**
     * The name of the field the token is posted in. A field key starts with a letter, so this one
     * never clashes.
     */
    public const string FIELD = '_recaptcha';

    public const string DEFAULT_ACTION = 'submit';

    public function __construct(
        private RecaptchaVerifier $verifier,
        private TranslatorInterface $translator,
        private string $siteKey,
        private float $scoreThreshold = 0.5,
        private bool $enabled = true,
        private bool $failOpen = true,
        private string $action = self::DEFAULT_ACTION,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function buildStep(FormBuilderInterface $builder, FormDefinition $form, StepDefinition $step): void
    {
        if ($this->isActive()) {
            $builder->add(self::FIELD, RecaptchaType::class, ['site_key' => $this->siteKey, 'recaptcha_action' => $this->action]);
        }
    }

    public function check(SpamCheckRequest $request): SpamCheckResult
    {
        if (!$this->isActive()) {
            return SpamCheckResult::passed();
        }

        $token = trim($request->value(self::FIELD));

        if ($token === '') {
            return SpamCheckResult::spam('No reCAPTCHA token was sent: the browser did not run the reCAPTCHA script.');
        }

        try {
            $verification = $this->verifier->verify($token, $request->request?->getClientIp());
        } catch (RecaptchaUnavailableException $exception) {
            $this->logger?->warning('reCAPTCHA was unavailable for the form "{form}": {message}', ['form' => $request->form->key, 'message' => $exception->getMessage()]);

            return $this->failOpen
                ? SpamCheckResult::passed()
                : SpamCheckResult::retry($this->translator->trans('eekes_form_builder_recaptcha.unavailable', [], 'eekes_form_builder_recaptcha', $request->form->locale));
        }

        if ($verification->isExpired()) {
            return SpamCheckResult::retry($this->translator->trans('eekes_form_builder_recaptcha.expired', [], 'eekes_form_builder_recaptcha', $request->form->locale));
        }

        if (!$verification->success) {
            return SpamCheckResult::spam(\sprintf('reCAPTCHA refused the token (%s).', implode(', ', $verification->errorCodes) ?: 'no reason given'));
        }

        if ($verification->action !== null && $verification->action !== $this->action) {
            return SpamCheckResult::spam(\sprintf('The token was made for the action "%s" instead of "%s".', $verification->action, $this->action), $verification->score);
        }

        if ($verification->score !== null && $verification->score < $this->scoreThreshold) {
            return SpamCheckResult::spam(\sprintf('reCAPTCHA scored it %.1f, below the threshold of %.1f.', $verification->score, $this->scoreThreshold), $verification->score);
        }

        return SpamCheckResult::passed();
    }

    /**
     * Switched off, or without a site key (a local machine, an environment without keys): then it
     * adds nothing and lets everything through, instead of blocking every visitor.
     */
    private function isActive(): bool
    {
        return $this->enabled && $this->siteKey !== '';
    }
}
