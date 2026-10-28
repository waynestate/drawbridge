<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The GitLab pipeline is the only place the gateway image is built and published, and this repository is mirrored
 * publicly, so the pipeline file is checked here: publishing happens only on tags, nothing runs privileged, and no
 * hostname, group name or token is named. The manual rig job keeps its Docker-in-Docker service (OPEN_QUESTIONS.md,
 * C-4); the image jobs must not use it.
 */
final class GitlabCiTest extends TestCase
{
    /** The jobs that build and publish the gateway image. */
    private const IMAGE_JOBS = ['gateway-image:build', 'gateway-image:publish'];

    private const CI_FILE = __DIR__ . '/../../.gitlab-ci.yml';

    /**
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        $pipeline = Yaml::parseFile(self::CI_FILE);
        $this->assertIsArray($pipeline);

        return $pipeline;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function jobs(): array
    {
        $jobs = [];
        foreach ($this->pipeline() as $name => $job) {
            if ($name === 'include' || ! \is_array($job)) {
                continue;
            }
            $jobs[(string) $name] = $job;
        }

        return $jobs;
    }

    /**
     * @return array<string, mixed>
     */
    private function job(string $name): array
    {
        $jobs = $this->jobs();
        $this->assertArrayHasKey($name, $jobs, "the pipeline must define $name");

        return $jobs[$name];
    }

    public function testTheImageJobsExist(): void
    {
        $jobs = $this->jobs();
        foreach (self::IMAGE_JOBS as $name) {
            $this->assertArrayHasKey($name, $jobs, "the pipeline must define $name");
        }
    }

    public function testPublishRunsOnlyOnTags(): void
    {
        $job = $this->job('gateway-image:publish');
        $rules = $job['rules'] ?? null;
        $this->assertIsArray($rules, 'gateway-image:publish must declare rules');
        $this->assertNotEmpty($rules, 'gateway-image:publish must declare rules');
        foreach ($rules as $rule) {
            $this->assertIsArray($rule);
            $this->assertArrayHasKey('if', $rule, 'every rule of gateway-image:publish must be an if rule');
            $this->assertStringContainsString('$CI_COMMIT_TAG', (string) $rule['if']);
        }
        $this->assertNotTrue($job['allow_failure'] ?? false, 'gateway-image:publish must not be allowed to fail');
    }

    public function testBuildRunsOnMergeRequestsAndTheDefaultBranch(): void
    {
        $job = $this->job('gateway-image:build');
        $rules = (array) ($job['rules'] ?? []);
        $conditions = array_map(
            static fn(mixed $rule): string => \is_array($rule) ? (string) ($rule['if'] ?? '') : '',
            $rules,
        );
        $this->assertContains('$CI_PIPELINE_SOURCE == "merge_request_event"', $conditions);
        $this->assertContains('$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH', $conditions);
    }

    public function testNoJobRunsPrivileged(): void
    {
        foreach ($this->jobs() as $name => $job) {
            $this->assertNotTrue($job['privileged'] ?? false, "$name must not set privileged: true");
        }
    }

    public function testNoJobMountsTheDockerSocket(): void
    {
        $this->assertStringNotContainsString('docker.sock', (string) file_get_contents(self::CI_FILE));
    }

    public function testTheImageJobsDoNotUseDindOrKaniko(): void
    {
        foreach (self::IMAGE_JOBS as $name) {
            $job = $this->job($name);
            foreach ((array) ($job['services'] ?? []) as $service) {
                $this->assertDoesNotMatchRegularExpression(
                    '#(?:^|/)docker:(?:dind|.+-dind)$#i',
                    (string) $service,
                    "$name must not use a Docker-in-Docker service",
                );
            }
            $this->assertStringNotContainsString(
                'kaniko',
                strtolower((string) ($job['image'] ?? '')),
                "$name must not use a kaniko image",
            );
        }
    }

    public function testTheFileNamesNoHostname(): void
    {
        $content = (string) file_get_contents(self::CI_FILE);
        // A hostname is a dotted name whose last segment is alphabetic (a TLD), in a host position: followed by /,
        // : or whitespace, and not part of a path (no leading /). That keeps version numbers (v1.43.5), file names
        // in prose (OPEN_QUESTIONS.md) and script paths (rig/run.sh) out of the match.
        preg_match_all(
            '#(?<!/)\b[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}\b(?=[/:]|\s|$)#im',
            $content,
            $matches,
        );
        $hostnames = array_values(array_unique($matches[0]));
        sort($hostnames);
        // The only literal hostname allowed is the public upstream that hosts the pinned Buildah image.
        $this->assertSame(['quay.io'], $hostnames, 'no hostname, group name or token may appear in the pipeline file');
    }
}
