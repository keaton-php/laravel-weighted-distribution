<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Support;

use LogicException;

/**
 * Runs the PHP blocks of the README and the docs pages as written.
 *
 * A block that starts with `class`, `enum` or `interface` is a definition: it is declared once,
 * before any script runs, and a block that repeats a definition must match the first one exactly.
 * Any other PHP block is a script. A script is run and, when a `text` block follows it, that
 * block is what the script has to print.
 */
final class DocsRunner
{
    private const ROOT = __DIR__.'/../..';

    private static bool $booted = false;

    /** @var array<string, string> class name => the code that declared it */
    private static array $declared = [];

    /** @return array<string, string> source name => its text: the README, then index.md, then the numbered pages */
    public static function sources(): array
    {
        $files = glob(self::ROOT.'/docs/*.md') ?: [];

        usort($files, fn (string $a, string $b) => [basename($a) !== 'index.md', basename($a)] <=> [basename($b) !== 'index.md', basename($b)]);

        $sources = ['README.md' => (string) file_get_contents(self::ROOT.'/README.md')];

        foreach ($files as $file) {
            $sources['docs/'.basename($file)] = (string) file_get_contents($file);
        }

        return $sources;
    }

    /** @return list<array{kind: string, code: string, output: ?string}> the code blocks of one page, in order */
    public static function blocks(string $markdown): array
    {
        preg_match_all('/^```(\w+)\n(.*?)\n```$/ms', $markdown, $found, PREG_SET_ORDER);

        $blocks = [];

        foreach ($found as [, $language, $code]) {
            if ($language === 'php') {
                $blocks[] = ['kind' => self::isDefinition($code) ? 'definition' : 'script', 'code' => $code, 'output' => null];
            } elseif ($language === 'text' && $blocks !== []) {
                $last = array_key_last($blocks);

                if ($blocks[$last]['kind'] === 'script' && $blocks[$last]['output'] === null) {
                    $blocks[$last]['output'] = $code;
                }
            }
        }

        return $blocks;
    }

    /** Load the helper classes and declare every definition the pages show. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        require_once __DIR__.'/../Fixtures/DocsHelpers.php';

        foreach (self::sources() as $name => $markdown) {
            foreach (self::blocks($markdown) as $block) {
                if ($block['kind'] !== 'definition') {
                    continue;
                }

                if (preg_match('/^(?:final |abstract )?(?:class|enum|interface) (\w+)/m', $block['code'], $class) !== 1) {
                    throw new LogicException("{$name} has a definition block with no class name");
                }

                $className = $class[1];

                if (isset(self::$declared[$className])) {
                    if (self::$declared[$className] !== $block['code']) {
                        throw new LogicException("{$name} shows {$className} differently from an earlier page");
                    }

                    continue;
                }

                self::$declared[$className] = $block['code'];
                self::evaluate($block['code']);
            }
        }

        self::$booted = true;
    }

    /** @return array{output: string, vars: array<string, mixed>} what the script printed and the variables it left */
    public static function run(string $script): array
    {
        self::boot();

        ob_start();

        try {
            $vars = (static fn (): array => self::evaluateWithVars($script))();
        } finally {
            $printed = (string) ob_get_clean();
        }

        return ['output' => $printed, 'vars' => $vars];
    }

    /** @return list<string> */
    public static function declaredClasses(): array
    {
        self::boot();

        return array_keys(self::$declared);
    }

    private static function isDefinition(string $code): bool
    {
        return preg_match('/\A<\?php\n\n(?:use [^\n]+\n)*\n?(?:final |abstract )?(?:class|enum|interface) /', $code) === 1;
    }

    private static function strip(string $code): string
    {
        return preg_replace('/\A<\?php\s*/', '', $code) ?? $code;
    }

    private static function evaluate(string $code): void
    {
        eval(self::strip($code));
    }

    /** @return array<string, mixed> */
    private static function evaluateWithVars(string $code): array
    {
        eval(self::strip($code));

        return get_defined_vars();
    }
}
