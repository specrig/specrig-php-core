<?php

declare(strict_types=1);

namespace Specrig;

final class Features
{
    private const SCENARIO = ['Scenario Outline:', 'Scenario Template:', 'Scenario:', 'Example:'];

    /** @var list<Scenario> */
    private array $cases = [];

    public function __construct(string $dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $path => $info) {
            if (str_ends_with($path, '.feature')) {
                $files[] = substr($path, strlen(rtrim($dir, '/')) + 1);
            }
        }
        foreach (Strings::sort($files) as $file) {
            array_push($this->cases, ...self::parse($file, file("{$dir}/{$file}", FILE_IGNORE_NEW_LINES)));
        }
    }

    /** @return list<Scenario> */
    public function cases(): array
    {
        return $this->cases;
    }

    /**
     * @param list<string> $lines
     * @return list<Scenario>
     */
    private static function parse(string $file, array $lines): array
    {
        $found = [];
        $feature = '';
        $featureIgnored = false;
        $tags = [];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if (str_starts_with($line, '@')) {
                array_push($tags, ...preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY));
                continue;
            }
            if (str_starts_with($line, 'Feature:')) {
                $feature = trim(substr($line, strlen('Feature:')));
                $featureIgnored = in_array('@ignore', $tags, true);
                $tags = [];
                continue;
            }
            if (($title = self::scenario($line)) !== null) {
                $found[] = new Scenario($file, $feature, $title, $featureIgnored || in_array('@ignore', $tags, true));
                $tags = [];
                continue;
            }
            if ($line !== '' && !str_starts_with($line, '#')) {
                $tags = [];
            }
        }
        return $found;
    }

    private static function scenario(string $line): ?string
    {
        foreach (self::SCENARIO as $keyword) {
            if (str_starts_with($line, $keyword)) {
                return trim(substr($line, strlen($keyword)));
            }
        }
        return null;
    }
}
