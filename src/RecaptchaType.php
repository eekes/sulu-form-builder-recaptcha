<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderRecaptchaBundle;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The hidden field the token is posted in. Its row (block "eekes_form_builder_recaptcha_row")
 * loads Google's script and asks for a fresh token just before the form is sent, so the token is
 * never older than the two minutes Google accepts.
 */
final class RecaptchaType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['site_key'] = $options['site_key'];
        $view->vars['recaptcha_action'] = $options['recaptcha_action'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('site_key')->required()->allowedTypes('string');
        $resolver->define('recaptcha_action')->default(RecaptchaSpamCheck::DEFAULT_ACTION)->allowedTypes('string');
        $resolver->setDefaults([
            'mapped' => false,
            'required' => false,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_recaptcha';
    }
}
