<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Adds "reCAPTCHA v3" to the spam checks of eekes/sulu-form-builder-bundle. Editors switch it on
 * per form, in the Spam protection section of the form's Settings tab.
 *
 * ```yaml
 * eekes_sulu_form_builder_recaptcha:
 *     site_key: '%env(RECAPTCHA_SITE_KEY)%'
 *     secret_key: '%env(RECAPTCHA_SECRET_KEY)%'
 * ```
 */
final class EekesSuluFormBuilderRecaptchaBundle extends AbstractBundle
{
    protected string $extensionAlias = 'eekes_sulu_form_builder_recaptcha';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('site_key')
                    ->defaultValue('')
                    ->info('The site key of a reCAPTCHA v3 site, e.g. %env(RECAPTCHA_SITE_KEY)%. A v2 key does not work.')
                ->end()
                ->scalarNode('secret_key')
                    ->defaultValue('')
                    ->info('The secret key of the same site, e.g. %env(RECAPTCHA_SECRET_KEY)%.')
                ->end()
                ->floatNode('score_threshold')
                    ->defaultValue(0.5)
                    ->min(0.0)
                    ->max(1.0)
                    ->info('Submissions scored below this are blocked. 0.5 is Google\'s advice; look at the Spam tab before raising it.')
                ->end()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Off: the check lets everything through and loads nothing, e.g. on a local machine.')
                ->end()
                ->booleanNode('fail_open')
                    ->defaultTrue()
                    ->info('When Google cannot be reached, let the submission through (true) or ask the visitor to send again (false).')
                ->end()
                ->scalarNode('action')
                    ->defaultValue(RecaptchaSpamCheck::DEFAULT_ACTION)
                    ->cannotBeEmpty()
                    ->info('The action tokens are requested for; shown in the reCAPTCHA console.')
                ->end()
                ->floatNode('timeout')->defaultValue(5.0)->end()
                ->scalarNode('http_client')->defaultValue('http_client')->cannotBeEmpty()->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The token field renders through a form theme of its own, next to whichever theme the
        // forms use.
        $builder->prependExtensionConfig('twig', ['form_themes' => ['@EekesSuluFormBuilderRecaptcha/form/recaptcha.html.twig']]);
    }

    /**
     * @param array{site_key: string, secret_key: string, score_threshold: float, enabled: bool, fail_open: bool, action: string, timeout: float, http_client: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(RecaptchaVerifier::class)
            ->args([service($config['http_client']), $config['secret_key'], $config['timeout']]);

        $services->set(RecaptchaType::class)->tag('form.type');

        // Autoconfigured, so the AsSpamCheck attribute registers it with the form builder.
        $services->set(RecaptchaSpamCheck::class)
            ->args([
                service(RecaptchaVerifier::class),
                service('translator'),
                $config['site_key'],
                $config['score_threshold'],
                $config['enabled'],
                $config['fail_open'],
                $config['action'],
                service('logger')->ignoreOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'form_builder'])
            ->autoconfigure();
    }
}
