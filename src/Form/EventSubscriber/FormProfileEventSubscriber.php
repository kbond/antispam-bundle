<?php

/*
 * Symfony Anti-Spam Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\AntiSpamBundle\Form\EventSubscriber;

use Omines\AntiSpamBundle\AntiSpamBundle;
use Omines\AntiSpamBundle\Form\AntiSpamFormError;
use Omines\AntiSpamBundle\Form\Type\HoneypotType;
use Omines\AntiSpamBundle\Form\Type\SubmitTimerType;
use Omines\AntiSpamBundle\Profile;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\ClearableErrorsInterface;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Autoconfigure(shared: false)]
class FormProfileEventSubscriber implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    private Profile $profile;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function setProfile(Profile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * @return array<FormEvents::*, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::PRE_SET_DATA => 'onPreSetData',
            FormEvents::POST_SET_DATA => 'onPostSetData',
            FormEvents::POST_SUBMIT => 'onPostSubmit',
        ];
    }

    public function onPreSetData(FormEvent $event): void
    {
        /*
         * Symfony Forms does not expose modifying options after creation, so the only way
         * to reliably modify options based on outside factors is to recreate the child
         * from its parent with updated options. Hence why the code below is so complex
         * instead of just creating a TextType extension. This is thankfully completely
         * safe as the Forms recognize which elements were added in the event handler.
         */
        foreach ($event->getForm()->all() as $name => $child) {
            $config = $child->getConfig();
            $type = $config->getType();
            while (null !== $type) {
                if ($type->getInnerType() instanceof TextType) {
                    $options = $config->getOptions();
                    $this->applyTextTypeProfile($options);
                    $event->getForm()->add($name, $config->getType()->getInnerType()::class, $options);
                    /* @infection-ignore-all don't try to make this into an endless loop kthnxbye */
                    break;
                }
                $type = $type->getParent();
            }
        }
    }

    public function onPostSetData(FormEvent $event): void
    {
        $form = $event->getForm();

        // Add honeypot field as required
        if ($honeypot = $this->profile->getHoneypotConfig()) {
            $form->add(self::uniqueFieldName($form, $honeypot['field']), HoneypotType::class);
        }

        // Add hidden and signed timer field
        if ($timer = $this->profile->getTimerConfig()) {
            if ($form->isRoot()) {
                $form->add(self::uniqueFieldName($form, $timer['field']), SubmitTimerType::class, [
                    'min' => $timer['min'],
                    'max' => $timer['max'],
                ]);
            } else {
                $this->logger?->info(sprintf('Ignoring timer configuration from profile "%s" on embedded form', $this->profile->getName()));
            }
        }
    }

    /**
     * Merge all AntiSpamFormError instances into one if we are running a stealthy operation in this form profile.
     */
    public function onPostSubmit(PostSubmitEvent $event): void
    {
        if ($this->profile->getStealth()) {
            $form = $event->getForm();
            assert($form instanceof ClearableErrorsInterface);

            $iterator = $form->getErrors();
            $form->clearErrors();
            $causes = [];
            foreach ($iterator as $error) {
                if ($error instanceof AntiSpamFormError) {
                    $causes[] = $error;
                } else {
                    $form->addError($error);
                }
            }
            if (!empty($causes)) {
                // Add a single error to replace all the aggregated ones
                $message = $this->translator->trans('form.stealthed', domain: AntiSpamBundle::TRANSLATION_DOMAIN);
                $form->addError(new AntiSpamFormError($message, 'form.stealthed'));
            }
        }
    }

    /**
     * @param array{constraints: array<Constraint>} $options
     */
    protected function applyTextTypeProfile(array &$options): void
    {
        if (!empty($constraints = $this->profile->getTextTypeConstraints())) {
            $options['constraints'][] = new Sequentially($constraints);
        }
    }

    /**
     * @infection-ignore-all Infection creates an endless loop here
     */
    private static function uniqueFieldName(FormInterface $form, string $basename): string
    {
        $field = $basename;
        $counter = 0;
        while ($form->has($field)) {
            $field = $basename . ++$counter;
        }

        return $field;
    }
}
