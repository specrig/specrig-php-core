<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeStepTested;
use Behat\Behat\EventDispatcher\Event\ExampleTested;
use Behat\Behat\EventDispatcher\Event\ScenarioTested;
use Behat\Behat\EventDispatcher\Event\StepTested;
use Behat\Gherkin\Node\ExampleNode;
use Behat\Testwork\EventDispatcher\Event\ExerciseCompleted;
use Specrig\Rig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class Listener implements EventSubscriberInterface
{
    public function __construct(private readonly Cases $cases)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StepTested::BEFORE => 'step',
            ScenarioTested::AFTER => ['ended', -100],
            ExampleTested::AFTER => ['ended', -100],
            ExerciseCompleted::AFTER => ['completed', -1000],
        ];
    }

    public function step(BeforeStepTested $event): void
    {
        $this->cases->running()?->trace->act(match ($event->getStep()->getKeywordType()) {
            'Given' => 'Context',
            'When' => 'Action',
            default => 'Outcome',
        });
    }

    public function ended(AfterScenarioTested $event): void
    {
        $scenario = $event->getScenario();
        $title = $scenario instanceof ExampleNode ? $scenario->getOutlineTitle() : $scenario->getTitle();
        $this->cases->ended((string) $event->getFeature()->getFile(), strtok((string) $title, "\n") ?: '');
    }

    public function completed(): void
    {
        try {
            $this->cases->write();
        } finally {
            Rig::endRun();
        }
    }
}
