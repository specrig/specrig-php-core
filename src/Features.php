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
        $outline = null;
        $at = 0;
        $rowsIgnored = null;
        $header = null;
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
                $at = count($found);
                $found[] = $outline = new Scenario($file, $feature, $title, $featureIgnored || in_array('@ignore', $tags, true));
                $tags = [];
                $rowsIgnored = null;
                continue;
            }
            if ($outline !== null && (str_starts_with($line, 'Examples:') || str_starts_with($line, 'Scenarios:'))) {
                // An outline runs as its rows, never under its own title.
                unset($found[$at]);
                $rowsIgnored = $outline->ignored || in_array('@ignore', $tags, true);
                $header = null;
                $tags = [];
                continue;
            }
            if ($rowsIgnored !== null && str_starts_with($line, '|')) {
                $cells = self::cells($line);
                if ($header === null) {
                    $header = $cells;
                } else {
                    $found[] = new Scenario($file, $feature, self::filled($outline->title, $header, $cells), $rowsIgnored);
                }
                continue;
            }
            if ($line !== '' && !str_starts_with($line, '#')) {
                $tags = [];
                // Past its header a table ends at the first other line, such as a Rule's Background.
                if ($header !== null) {
                    $rowsIgnored = null;
                }
            }
        }
        return array_values($found);
    }

    /**
     * The title an outline runs under for one row of its examples, filled as gherkin fills it.
     *
     * @param list<string> $columns
     * @param list<string> $values
     */
    public static function filled(string $title, array $columns, array $values): string
    {
        for ($i = 0; $i < min(count($columns), count($values)); $i++) {
            $title = str_replace("<{$columns[$i]}>", $values[$i], $title);
        }
        return $title;
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        preg_match_all('/(?:[^|\\\\]|\\\\.)*\|/', substr($line, 1), $cells);
        // gherkin's escapes in a table cell.
        return array_map(fn (string $c) => trim(strtr(substr($c, 0, -1), ['\\|' => '|', '\\\\' => '\\', '\\n' => "\n"])), $cells[0]);
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
