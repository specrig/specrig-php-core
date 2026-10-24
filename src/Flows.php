<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Flows\Branch;
use Specrig\Flows\Doc;
use Specrig\Flows\Input;
use Specrig\Flows\Orphan;
use Specrig\Flows\Output;
use Symfony\Component\Yaml\Yaml as Parser;

final class Flows
{
    public const FORMAT = 'flows/1';

    private const HEADER =
        "# Generated from the component suite by specrig: every whole run rewrites the\n" .
        "# structure; only the description fields are yours.\n";

    /** @param iterable<Run> $runs */
    public static function build(string $service, iterable $runs): Doc
    {
        $runs = [...$runs];
        usort($runs, fn (Run $a, Run $b) => strcmp($a->case, $b->case));
        $inputs = [];
        $branches = [];
        $fields = [];
        $outputs = [];
        $received = [];
        $sentFields = [];

        foreach ($runs as $run) {
            $records = $run->records;
            usort($records, fn (Record $a, Record $b) => $a->seq <=> $b->seq);
            $marked = array_filter($records, fn (Record $r) => $r->step !== '') !== [];
            $acting = fn (Record $r) => !$marked || $r->step === 'Action';
            $last = -1;
            $sent = [];
            $carried = [];
            foreach ($records as $i => $r) {
                $message = "{$r->kind} {$r->name}";
                if ($r->dir === 'in' && $acting($r)) {
                    $last = $i;
                    $sent[$i] = [];
                    $carried[$i] = [];
                    if (!isset($inputs[$message])) {
                        $inputs[$message] = new Input($message);
                        $branches[$message] = [];
                        $fields[$message] = [];
                    }
                    self::paths($r->request, $fields[$message]);
                } elseif ($r->dir === 'out') {
                    $outputs[$message] ??= [];
                    self::paths($r->request, $outputs[$message]);
                    if ($last >= 0 && $acting($r)) {
                        $sent[$last][] = $message;
                        $carried[$last][$message] ??= [];
                        self::paths($r->request, $carried[$last][$message]);
                    }
                }
            }
            ksort($sent);
            foreach ($sent as $i => $sends) {
                $r = $records[$i];
                $message = "{$r->kind} {$r->name}";
                $passes = implode("\n", self::passes($sends));
                $key = $passes . "\0" . ($r->error ?? '');
                if (!isset($branches[$message][$key])) {
                    $branch = new Branch($passes === '' ? null : explode("\n", $passes), ($r->error ?? '') === '' ? null : $r->error);
                    $branches[$message][$key] = $branch;
                    $received[spl_object_id($branch)] = ['every' => null, 'any' => []];
                    $sentFields[spl_object_id($branch)] = [];
                }
                $branch = $branches[$message][$key];
                $id = spl_object_id($branch);
                if (!in_array($run->case, $branch->cases, true)) {
                    $branch->cases[] = $run->case;
                }
                $got = [];
                self::paths($r->request, $got);
                $shape = &$received[$id];
                $shape['every'] = $shape['every'] === null ? $got : array_intersect_key($shape['every'], $got);
                $shape['any'] += $got;
                unset($shape);
                foreach ($carried[$i] as $to => $carriedFields) {
                    $sentFields[$id][$to] = ($sentFields[$id][$to] ?? []) + $carriedFields;
                }
            }
        }

        $doc = new Doc($service);
        foreach (Strings::byKey($inputs) as $message => $input) {
            $message = (string) $message;
            $input->fields = $fields[$message] === [] ? null : Strings::sorted($fields[$message]);
            $answered = array_values(array_filter($branches[$message], fn (Branch $b) => $b->refused === null));
            $input->needs = self::needs($received, $answered);
            foreach ($branches[$message] as $branch) {
                $branch->cases = Strings::sort($branch->cases);
                $carried = array_filter($sentFields[spl_object_id($branch)], fn (array $set) => $set !== []);
                $branch->sent = $carried === [] ? null : array_map(Strings::sorted(...), Strings::byKey($carried));
            }
            $input->branches = array_values($branches[$message]);
            usort($input->branches, fn (Branch $a, Branch $b) => strcmp($a->anchorIn($input), $b->anchorIn($input)));
            $doc->inputs[] = $input;
        }
        if ($outputs !== []) {
            foreach (Strings::byKey($outputs) as $message => $set) {
                $doc->outputs[] = new Output((string) $message, $set === [] ? null : Strings::sorted($set));
            }
        }
        return $doc;
    }

    // A need splits the answered branches exactly, or fixtures naming a unit in most
    // cases would make the unit a need of the promo redeemed in some of them.
    /**
     * @param array<int, array{every: ?array<array-key, true>, any: array<array-key, true>}> $received
     * @param list<Branch> $answered
     * @return array<string, list<string>>|null
     */
    private static function needs(array $received, array $answered): ?array
    {
        $needs = [];
        $messages = array_unique(array_merge(...array_map(fn (Branch $b) => $b->sends ?? [], $answered)));
        foreach ($messages as $message) {
            $with = array_values(array_filter($answered, fn (Branch $b) => in_array($message, $b->sends ?? [], true)));
            $without = array_values(array_filter($answered, fn (Branch $b) => !in_array($b, $with, true)));
            if ($without === []) {
                continue;
            }
            $split = $received[spl_object_id($with[0])]['every'] ?? [];
            foreach (array_slice($with, 1) as $b) {
                $split = array_intersect_key($split, $received[spl_object_id($b)]['every'] ?? []);
            }
            foreach ($without as $b) {
                $split = array_diff_key($split, $received[spl_object_id($b)]['any']);
            }
            if ($split !== []) {
                $needs[$message] = Strings::sorted($split);
            }
        }
        return $needs === [] ? null : Strings::byKey($needs);
    }

    /**
     * @param list<string> $sends
     * @return list<string>
     */
    private static function passes(array $sends): array
    {
        $passes = $sends;
        for ($folded = true; $folded;) {
            $folded = false;
            for ($size = 1; $size <= intdiv(count($passes), 2) && !$folded; $size++) {
                for ($i = 0; $i + 2 * $size <= count($passes); $i++) {
                    if (array_slice($passes, $i, $size) === array_slice($passes, $i + $size, $size)) {
                        array_splice($passes, $i + $size, $size);
                        $folded = true;
                        break;
                    }
                }
            }
        }
        return $passes;
    }

    /** @param array<array-key, true> $into */
    private static function paths(mixed $body, array &$into, string $prefix = ''): void
    {
        if ($body instanceof \stdClass || is_array($body) && !array_is_list($body)) {
            foreach ((array) $body as $key => $child) {
                self::paths($child, $into, $prefix === '' ? (string) $key : "{$prefix}.{$key}");
            }
        } elseif (is_array($body)) {
            foreach ($body as $child) {
                self::paths($child, $into, "{$prefix}[]");
            }
        } elseif ($body !== null && $prefix !== '') {
            $into[$prefix] = true;
        }
    }

    public static function reconcile(?Doc $old, Doc $fresh): Doc
    {
        $prose = [];
        foreach ($old?->inputs ?? [] as $input) {
            if ($input->description !== null) {
                $prose[$input->anchor()] = $input->description;
            }
            foreach ($input->branches as $branch) {
                if ($branch->description !== null) {
                    $prose[$branch->anchorIn($input)] = $branch->description;
                }
            }
        }
        $orphaned = [];
        foreach ($old?->orphaned ?? [] as $orphan) {
            if (isset($prose[$orphan->anchor])) {
                $orphaned[] = $orphan;
            } elseif ($orphan->description !== null) {
                $prose[$orphan->anchor] = $orphan->description;
            }
        }
        $used = [];
        foreach ($fresh->inputs as $input) {
            if (isset($prose[$input->anchor()])) {
                $input->description = $prose[$input->anchor()];
                $used[$input->anchor()] = true;
            }
            foreach ($input->branches as $branch) {
                if (isset($prose[$branch->anchorIn($input)])) {
                    $branch->description = $prose[$branch->anchorIn($input)];
                    $used[$branch->anchorIn($input)] = true;
                }
            }
        }
        foreach (Strings::byKey($prose) as $anchor => $description) {
            if (!isset($used[$anchor])) {
                $orphaned[] = new Orphan((string) $anchor, $description);
            }
        }
        $fresh->orphaned = $orphaned === [] ? null : $orphaned;
        return $fresh;
    }

    public static function read(string $file): ?Doc
    {
        if (!is_file($file)) {
            return null;
        }
        $data = Parser::parseFile($file);
        if (!is_array($data)) {
            return null;
        }
        $doc = new Doc((string) ($data['service'] ?? ''));
        $doc->specrig = (string) ($data['specrig'] ?? self::FORMAT);
        foreach ($data['inputs'] ?? [] as $in) {
            $input = new Input((string) $in['message']);
            $input->description = $in['description'] ?? null;
            $input->fields = $in['fields'] ?? null;
            $input->needs = $in['needs'] ?? null;
            foreach ($in['branches'] ?? [] as $b) {
                $branch = new Branch($b['sends'] ?? null, $b['refused'] ?? null);
                $branch->sent = $b['sent'] ?? null;
                $branch->description = $b['description'] ?? null;
                $branch->cases = $b['cases'] ?? [];
                $input->branches[] = $branch;
            }
            $doc->inputs[] = $input;
        }
        foreach ($data['outputs'] ?? [] as $out) {
            $doc->outputs[] = new Output((string) $out['message'], $out['fields'] ?? null);
        }
        foreach ($data['orphaned'] ?? [] as $orphan) {
            $doc->orphaned[] = new Orphan((string) $orphan['anchor'], $orphan['description'] ?? null, $orphan['title'] ?? null);
        }
        return $doc;
    }

    public static function encode(Doc $doc): string
    {
        return self::HEADER . Yaml::emit([
            'specrig' => $doc->specrig,
            'service' => $doc->service,
            'inputs' => array_map(fn (Input $input) => [
                'message' => $input->message,
                'description' => $input->description,
                'fields' => $input->fields,
                'needs' => $input->needs,
                'branches' => array_map(fn (Branch $branch) => [
                    'sends' => $branch->sends,
                    'sent' => $branch->sent,
                    'refused' => $branch->refused,
                    'description' => $branch->description,
                    'cases' => $branch->cases,
                ], $input->branches),
            ], $doc->inputs),
            'outputs' => array_map(fn (Output $output) => [
                'message' => $output->message,
                'fields' => $output->fields,
            ], $doc->outputs ?? []),
            'orphaned' => array_map(fn (Orphan $orphan) => [
                'anchor' => $orphan->anchor,
                'title' => $orphan->title,
                'description' => $orphan->description,
            ], $doc->orphaned ?? []),
        ]);
    }

    /** @param iterable<Run> $runs */
    public static function write(string $file, string $service, iterable $runs): int
    {
        $old = self::read($file);
        $fresh = self::build($service, $runs);
        if ($fresh->inputs === [] && ($old?->inputs ?? []) === []) {
            return 0;
        }
        $merged = self::reconcile($old, $fresh);
        $body = self::encode($merged);
        if (!is_file($file) || file_get_contents($file) !== $body) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $body);
        }
        return count($merged->orphaned ?? []);
    }
}
