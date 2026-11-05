<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle\Tests\Unit;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRequest;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Eekes\Sulu\FormBuilderRecaptchaBundle\RecaptchaSpamCheck;
use Eekes\Sulu\FormBuilderRecaptchaBundle\RecaptchaType;
use Eekes\Sulu\FormBuilderRecaptchaBundle\RecaptchaVerification;
use Eekes\Sulu\FormBuilderRecaptchaBundle\RecaptchaVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

#[CoversClass(RecaptchaSpamCheck::class)]
#[CoversClass(RecaptchaVerifier::class)]
#[CoversClass(RecaptchaVerification::class)]
#[CoversClass(RecaptchaType::class)]
final class RecaptchaSpamCheckTest extends TestCase
{
    /**
     * @var list<array{url: string, body: string}>
     */
    private array $calls = [];

    public function testAPersonScoredAboveTheThresholdPasses(): void
    {
        $result = $this->check(['success' => true, 'score' => 0.9, 'action' => 'submit'])->check($this->request('token-1'));

        self::assertTrue($result->isPassed());
        self::assertSame(RecaptchaVerifier::ENDPOINT, $this->calls[0]['url']);
        parse_str($this->calls[0]['body'], $body);
        self::assertSame(['secret' => 's3cret', 'response' => 'token-1', 'remoteip' => '203.0.113.7'], $body);
    }

    public function testALowScoreIsSpamWithItsScore(): void
    {
        $result = $this->check(['success' => true, 'score' => 0.1, 'action' => 'submit'])->check($this->request('token'));

        self::assertTrue($result->isSpam());
        self::assertSame(0.1, $result->score);
        self::assertSame('reCAPTCHA scored it 0.1, below the threshold of 0.5.', $result->message);
    }

    public function testAnExpiredTokenGivesTheVisitorTheStepBack(): void
    {
        $result = $this->check(['success' => false, 'error-codes' => ['timeout-or-duplicate']])->check($this->request('token'));

        self::assertTrue($result->isRetry());
        self::assertSame('eekes_form_builder_recaptcha.expired@nl', $result->message, 'In the language of the form.');
    }

    public function testARefusedTokenIsSpam(): void
    {
        $result = $this->check(['success' => false, 'error-codes' => ['invalid-input-response']])->check($this->request('token'));

        self::assertTrue($result->isSpam());
        self::assertSame('reCAPTCHA refused the token (invalid-input-response).', $result->message);
        self::assertStringNotContainsString('s3cret', $result->message, 'Reasons are shown in the admin.');
    }

    public function testATokenForAnotherActionIsSpam(): void
    {
        self::assertTrue($this->check(['success' => true, 'score' => 0.9, 'action' => 'login'])->check($this->request('token'))->isSpam());
    }

    public function testWithoutATokenTheBrowserDidNotRunTheScript(): void
    {
        $result = $this->check([])->check($this->request(''));

        self::assertTrue($result->isSpam());
        self::assertSame([], $this->calls, 'Google is not asked about nothing.');
    }

    public function testWhenGoogleCannotBeReachedTheSubmissionGoesThroughOrIsSentAgain(): void
    {
        self::assertTrue($this->check(new TransportException('timeout'))->check($this->request('token'))->isPassed(), 'By default a submission is not lost because Google is down.');

        $strict = $this->check(new TransportException('timeout'), failOpen: false)->check($this->request('token'));
        self::assertTrue($strict->isRetry());
        self::assertSame('eekes_form_builder_recaptcha.unavailable@nl', $strict->message);
    }

    public function testSwitchedOffItAddsNothingAndLetsEverythingThrough(): void
    {
        $check = $this->check([], enabled: false);
        $builder = $this->factory()->createNamedBuilder('step', FormType::class);
        $check->buildStep($builder, $this->definition(), $this->definition()->steps[0]);

        self::assertFalse($builder->has(RecaptchaSpamCheck::FIELD));
        self::assertTrue($check->check($this->request(''))->isPassed());
    }

    public function testWithoutASiteKeyItIsOff(): void
    {
        $check = $this->check([], siteKey: '');
        $builder = $this->factory()->createNamedBuilder('step', FormType::class);
        $check->buildStep($builder, $this->definition(), $this->definition()->steps[0]);

        self::assertFalse($builder->has(RecaptchaSpamCheck::FIELD), 'An environment without keys does not block every visitor.');
        self::assertTrue($check->check($this->request(''))->isPassed());
    }

    public function testEveryStepGetsATokenFieldThatIsNeverAnAnswer(): void
    {
        $builder = $this->factory()->createNamedBuilder('step', FormType::class);
        $this->check([])->buildStep($builder, $this->definition(), $this->definition()->steps[0]);

        $form = $builder->getForm();
        $form->submit([RecaptchaSpamCheck::FIELD => 'token']);
        $view = $form->createView()[RecaptchaSpamCheck::FIELD];

        self::assertSame([], $form->getData() ?? [], 'Unmapped: the token never reaches the answers.');
        self::assertSame('site-key', $view->vars['site_key']);
        self::assertSame('submit', $view->vars['recaptcha_action']);
        self::assertIsArray($view->vars['block_prefixes']);
        self::assertContains('eekes_form_builder_recaptcha', $view->vars['block_prefixes']);
    }

    /**
     * @param array<string, mixed>|\Throwable $answer what Google answers
     */
    private function check(array|\Throwable $answer, bool $enabled = true, bool $failOpen = true, string $siteKey = 'site-key'): RecaptchaSpamCheck
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $body = $options['body'] ?? '';
            $this->calls[] = ['url' => $url, 'body' => \is_string($body) ? $body : ''];

            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR));
        });

        $translator = new class implements TranslatorInterface {
            use TranslatorTrait;

            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.'@'.$locale;
            }
        };

        return new RecaptchaSpamCheck(new RecaptchaVerifier($client, 's3cret'), $translator, $siteKey, enabled: $enabled, failOpen: $failOpen);
    }

    private function request(string $token): SpamCheckRequest
    {
        $step = $this->factory()->createNamedBuilder('step', FormType::class)
            ->add(RecaptchaSpamCheck::FIELD, RecaptchaType::class, ['site_key' => 'site-key'])
            ->getForm();
        $step->submit([RecaptchaSpamCheck::FIELD => $token]);

        return new SpamCheckRequest($this->definition(), $step, Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));
    }

    private function definition(): FormDefinition
    {
        return new FormDefinition(1, 'contact', 'Contact', 'nl', [new StepDefinition('step1', 'Contact')]);
    }

    private function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()->addType(new RecaptchaType())->getFormFactory();
    }
}
