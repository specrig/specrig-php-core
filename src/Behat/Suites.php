<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Behat\Behat\Context\Context;
use Behat\Testwork\Suite\Generator\SuiteGenerator;
use Behat\Testwork\Suite\GenericSuite;
use Behat\Testwork\Suite\Suite;

// Every context in the steps directory is a context of every suite, so a new steps
// class needs no line in the platform's tests/behat.php.
final class Suites implements SuiteGenerator
{
    /** @param array<string, mixed> $defaults */
    public function __construct(private readonly array $defaults, private readonly string $steps)
    {
    }

    public function supportsTypeAndSettings($type, array $settings): bool
    {
        return $type === null;
    }

    public function generateSuite($suiteName, array $settings): Suite
    {
        $contexts = $settings['contexts'] ?? [];
        $settings = array_merge($this->defaults, $settings);
        $settings['contexts'] = array_values(array_unique([...$contexts, Checks::class, ...self::discover($this->steps)]));
        return new GenericSuite($suiteName, $settings);
    }

    /** @return list<class-string<Context>> */
    private static function discover(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path => $info) {
            if (str_ends_with((string) $path, '.php')) {
                foreach (self::classes((string) file_get_contents((string) $path)) as $class) {
                    if (is_subclass_of($class, Context::class) && !(new \ReflectionClass($class))->isAbstract()) {
                        $found[] = $class;
                    }
                }
            }
        }
        sort($found);
        return $found;
    }

    /** @return list<string> */
    private static function classes(string $code): array
    {
        $namespace = '';
        $classes = [];
        $tokens = \PhpToken::tokenize($code);
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = '';
                for ($j = $i + 1; isset($tokens[$j]) && !$tokens[$j]->is([';', '{']); $j++) {
                    $namespace .= $tokens[$j]->is([T_NAME_QUALIFIED, T_STRING]) ? $tokens[$j]->text : '';
                }
            } elseif ($token->is(T_CLASS) && !($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)) {
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->is(T_WHITESPACE); $j++) {
                }
                if (isset($tokens[$j]) && $tokens[$j]->is(T_STRING)) {
                    $classes[] = ltrim("{$namespace}\\{$tokens[$j]->text}", '\\');
                }
            }
        }
        return $classes;
    }
}
