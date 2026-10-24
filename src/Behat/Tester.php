<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Behat\Behat\Tester\ScenarioTester;
use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\ScenarioInterface;
use Behat\Testwork\Environment\Environment;
use Behat\Testwork\Tester\Result\IntegerTestResult;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Testwork\Tester\Setup\Setup;
use Behat\Testwork\Tester\Setup\Teardown;

// Behat counts a failed AfterScenario hook apart from the scenario; what the harness
// found fails the scenario itself, and the hook says what it was.
final class Tester implements ScenarioTester
{
    public function __construct(private readonly ScenarioTester $tester, private readonly Cases $cases)
    {
    }

    public function setUp(Environment $env, FeatureNode $feature, ScenarioInterface $scenario, $skip): Setup
    {
        return $this->tester->setUp($env, $feature, $scenario, $skip);
    }

    public function test(Environment $env, FeatureNode $feature, ScenarioInterface $scenario, $skip): TestResult
    {
        $result = $this->tester->test($env, $feature, $scenario, $skip);
        if ($result->isPassed() && ($this->cases->running()?->failures() ?? []) !== []) {
            return new IntegerTestResult(TestResult::FAILED);
        }
        return $result;
    }

    public function tearDown(Environment $env, FeatureNode $feature, ScenarioInterface $scenario, $skip, TestResult $result): Teardown
    {
        return $this->tester->tearDown($env, $feature, $scenario, $skip, $result);
    }
}
