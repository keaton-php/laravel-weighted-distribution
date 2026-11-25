<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Feature;

use Keaton\WeightedDistribution\Tests\Support\DocsRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The code examples in README.md, run as written.
 *
 * The README opens with a before and after: a weighted random pick written by hand, then the same
 * split with the package. A third example adds a cap. DocsExamplesTest runs each script and checks
 * its output against the block below it; this class checks the shape of the README itself.
 */
class ReadmeExamplesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    #[Test]
    public function the_readme_shows_a_before_and_an_after_and_the_output_each_prints(): void
    {
        $blocks = DocsRunner::blocks($this->readme());

        $this->assertSame(['script', 'script', 'script'], array_column($blocks, 'kind'));

        foreach ($blocks as $block) {
            $this->assertNotNull($block['output']);
        }

        $this->assertStringNotContainsString('WeightedDistribution', $blocks[0]['code'], 'The first example is the split written by hand');
        $this->assertStringContainsString('WeightedDistribution::make', $blocks[1]['code']);
    }

    #[Test]
    public function the_random_pick_in_the_readme_misses_the_ratio(): void
    {
        $leads = DocsRunner::run(DocsRunner::blocks($this->readme())[0]['code'])['vars']['leads'];

        $this->assertCount(10, $leads);
        $this->assertNotSame(['north' => 5, 'south' => 3, 'west' => 2], array_count_values($leads));
    }

    #[Test]
    public function the_package_example_in_the_readme_splits_the_leads_exactly_five_three_two(): void
    {
        $leads = DocsRunner::run(DocsRunner::blocks($this->readme())[1]['code'])['vars']['leads'];

        $this->assertSame(['north' => 5, 'south' => 3, 'west' => 2], array_count_values($leads));
        $this->assertSame('north south north west north south north west south north', implode(' ', $leads));
    }

    #[Test]
    public function the_readme_sends_readers_to_a_docs_page_that_exists(): void
    {
        $this->assertStringContainsString('https://kevineaton.dev/docs/laravel-weighted-distribution/basic-usage/', $this->readme());

        // A docs page's address is its file name without the NN- order prefix.
        $this->assertNotEmpty(glob(self::ROOT.'/docs/*-basic-usage.md'));
    }

    #[Test]
    public function the_readme_stays_short(): void
    {
        $lines = substr_count($this->readme(), "\n");

        $this->assertGreaterThanOrEqual(90, $lines);
        $this->assertLessThanOrEqual(200, $lines);
    }

    #[Test]
    public function the_readme_badge_points_at_the_1x_branch(): void
    {
        $this->assertStringContainsString('/laravel-weighted-distribution/run-tests.yml?branch=1.x', $this->readme());
        $this->assertFileExists(self::ROOT.'/.github/workflows/run-tests.yml');
        $this->assertStringNotContainsString('branch=main', $this->readme());
    }

    #[Test]
    public function every_relative_link_in_the_readme_points_at_a_file_in_the_repo(): void
    {
        preg_match_all('/\]\((?!https?:)([^)#]+)\)/', $this->readme(), $links);

        foreach (['CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'LICENSE.md'] as $footer) {
            $this->assertContains($footer, $links[1], "The README does not link to {$footer}");
        }

        foreach ($links[1] as $link) {
            $this->assertFileExists(self::ROOT.'/'.$link, "The README links to {$link}, which is not in the repo");
        }
    }

    private function readme(): string
    {
        return (string) file_get_contents(self::ROOT.'/README.md');
    }
}
