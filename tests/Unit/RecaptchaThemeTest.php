<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle\Tests\Unit;

use Eekes\Sulu\FormBuilderRecaptchaBundle\RecaptchaSpamCheck;
use Eekes\Sulu\FormBuilderRecaptchaBundle\RecaptchaType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * The token field as a page renders it: hidden, carrying the site key, with the script that loads
 * reCAPTCHA once and fetches a token before the form is sent.
 */
#[CoversNothing]
final class RecaptchaThemeTest extends TestCase
{
    public function testTheTokenFieldLoadsGoogleAndFetchesATokenOnSubmit(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('/<input type="hidden" id="step__recaptcha" name="step\[_recaptcha\]" data-eekes-recaptcha="site-key" data-eekes-recaptcha-action="submit"/', $html);
        self::assertStringContainsString("'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(\"site-key\")", $html);
        self::assertStringContainsString('if (!window.eekesRecaptcha)', $html, 'Loaded once, however many forms a page has.');
        self::assertStringContainsString("grecaptcha.execute(field.getAttribute('data-eekes-recaptcha')", $html);
        self::assertStringNotContainsString('<label', $html);
        self::assertStringContainsString("send('');", $html, 'A failing reCAPTCHA never leaves the form unsent.');
    }

    public function testASiteKeyCannotBreakOutOfTheScript(): void
    {
        self::assertStringNotContainsString('</script><script>alert(1)', $this->render('"</script><script>alert(1)//'));
    }

    private function render(string $siteKey = 'site-key'): string
    {
        $loader = new FilesystemLoader([\dirname(__DIR__, 2).'/vendor/symfony/twig-bridge/Resources/views/Form']);
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'EekesSuluFormBuilderRecaptcha');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());

        $engine = new TwigRendererEngine(['form_div_layout.html.twig', '@EekesSuluFormBuilderRecaptcha/form/recaptcha.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine)]));

        $form = Forms::createFormFactoryBuilder()->addType(new RecaptchaType())->getFormFactory()
            ->createNamedBuilder('step', FormType::class)
            ->add(RecaptchaSpamCheck::FIELD, RecaptchaType::class, ['site_key' => $siteKey])
            ->getForm();

        return $twig->createTemplate('{{ form_row(form._recaptcha) }}')->render(['form' => $form->createView()]);
    }
}
