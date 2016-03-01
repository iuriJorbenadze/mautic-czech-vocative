<?php
namespace MauticPlugin\MauticVocativeBundle\Tests\EventListener;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\LeadBundle\EventListener\EmailSubscriber;
use MauticPlugin\MauticVocativeBundle\EventListener\EmailNameToVocativeSubscriber;
use MauticPlugin\MauticVocativeBundle\Service\NameToVocativeConverter;
use MauticPlugin\MauticVocativeBundle\Tests\FOMTestWithMockery;

class EmailNameToVocativeSubscriberTest extends FOMTestWithMockery
{

    /**
     * @test
     */
    public function Conversion_reacts_both_on_send_and_view_of_email()
    {
        $this->assertEquals(
            array_keys(EmailNameToVocativeSubscriber::getSubscribedEvents()),
            [EmailEvents::EMAIL_ON_SEND, EmailEvents::EMAIL_ON_DISPLAY]
        );
    }

    /**
     * @test
     */
    public function Conversion_has_lower_priority_than_lead_events()
    {
        $leadEmailEventPriorities = $this->getLeadEmailEventsPriorities();
        foreach (EmailNameToVocativeSubscriber::getSubscribedEvents() as $eventName => $reaction) {
            $this->assertTrue(is_array($reaction));
            $priority = $this->filterPriorityValue($reaction);
            $this->assertArrayHasKey($eventName, $leadEmailEventPriorities);
            $this->assertLessThan($leadEmailEventPriorities[$eventName], $priority);
        }
    }

    /**
     * By event name indexed priorities
     * @return array|int[]
     */
    private function getLeadEmailEventsPriorities()
    {
        $subscribedEvents = EmailSubscriber::getSubscribedEvents();
        $lookedForEvents = [EmailEvents::EMAIL_ON_SEND, EmailEvents::EMAIL_ON_DISPLAY];
        $this->assertNotEmpty($lookedForEvents);
        $watchedEvents = array_filter(
            $subscribedEvents,
            function ($value) use ($subscribedEvents, $lookedForEvents) {
                $eventName = array_search($value, $subscribedEvents);

                return in_array($eventName, $lookedForEvents);
            }
        );
        $priorities = [];
        foreach ($watchedEvents as $eventName => $reaction) {
            $priority = $this->filterPriorityValue($reaction);
            $priorities[$eventName] = $priority;
        }
        $this->assertCount(count($lookedForEvents), $priorities);

        return $priorities;
    }

    private function filterPriorityValue(array $reaction)
    {
        $wrappedPriority = array_filter($reaction, function ($value) {
            return is_numeric($value);
        });
        $this->assertTrue(is_array($wrappedPriority));

        return current($wrappedPriority);
    }

    /**
     * @test
     */
    public function I_got_names_converted_in_email()
    {
        $this->checkEmailContentConversion('baz', 'baz');
    }

    /**
     * @param string $toReplace
     * @param string $toVocative
     */
    private function checkEmailContentConversion($toReplace, $toVocative)
    {
        $mauticFactory = $this->createMauticFactory();
        $subscriber = new EmailNameToVocativeSubscriber($mauticFactory);
        $emailSendEvent = $this->mockery(EmailSendEvent::class);
        $emailSendEvent->shouldReceive('getContent')
            ->atLeast()->once()
            ->andReturn('foo [' . $toReplace . '|vocative] bar');
        if ($toVocative !== false) {
            $nameConverter = $mauticFactory->getKernel()->getContainer()->get('plugin.vocative.name_converter');
            /** @var \Mockery\MockInterface $nameConverter */
            $nameConverter->shouldReceive('convert')
                ->with($toVocative)
                ->andReturn($inVocative = 'qux');
            $emailSendEvent->shouldReceive('setContent')
                ->atLeast()->once()
                ->with('foo ' . $inVocative . ' bar');
        } else {
            $nameConverter = $mauticFactory->getKernel()->getContainer()->get('plugin.vocative.name_converter');
            /** @var \Mockery\MockInterface $nameConverter */
            $nameConverter->shouldReceive('convert')
                ->never();
            $emailSendEvent->shouldReceive('setContent')
                ->atLeast()->once()
                ->with('foo  bar');
        }

        /** @var EmailSendEvent $emailSendEvent */
        $subscriber->onEmailGenerate($emailSendEvent);
    }

    /**
     * @return \Mockery\MockInterface|MauticFactory
     */
    private function createMauticFactory()
    {
        $mauticFactory = $this->mockery(MauticFactory::class);
        $mauticFactory->shouldReceive('getTemplating');
        $mauticFactory->shouldReceive('getRequest');
        $mauticFactory->shouldReceive('getSecurity');
        $mauticFactory->shouldReceive('getSerializer');
        $mauticFactory->shouldReceive('getSystemParameters');
        $mauticFactory->shouldReceive('getDispatcher');
        $mauticFactory->shouldReceive('getTranslator');
        $mauticFactory->shouldReceive('getKernel')
            ->andReturn($kernel = $this->mockery(\stdClass::class));
        $kernel->shouldReceive('getContainer')
            ->andReturn($container = $this->mockery(\stdClass::class));
        $container->shouldReceive('get')
            ->with('plugin.vocative.name_converter')
            ->andReturn($this->mockery(NameToVocativeConverter::class));

        return $mauticFactory;
    }

    /**
     * @test
     */
    public function I_got_names_converted_even_if_wrapped_by_white_space()
    {
        $this->checkEmailContentConversion("\t\n baz  \t\n ", 'baz');
    }

    /**
     * @test
     */
    public function I_do_not_trigger_conversion_by_empty_value()
    {
        $this->checkEmailContentConversion('', false /* conversion should not be called */);
    }

    /**
     * @test
     */
    public function I_got_removed_white_spaces_only_without_conversion_trigger()
    {
        $this->checkEmailContentConversion("\n\t\t    \n\t  ", false /* conversion should not be called */);
    }

}
