<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

final class PageRenderTest extends UiTestCase
{
    public function testDashboardRendersWithProjects(): void
    {
        $html = $this->renderTemplate('dashboard.twig', [
            'projects' => [[
                'id' => 1,
                'name' => 'mymodule',
                'path' => '/tmp/mymodule',
                'project_type' => 'drupal-module',
                'package_name' => 'drupal/mymodule',
                'root_name' => 'mymodule',
                'source_type' => 'local_path',
                'default_branch_record' => ['branch_name' => 'main'],
                'latest_run' => ['id' => 1, 'status' => 'completed', 'match_count' => 5],
            ]],
            'active_jobs' => [],
            'recent_jobs' => [],
        ]);

        $this->assertStringContainsString('mymodule', $html);
        $this->assertStringContainsString('drupal/mymodule', $html);
    }

    public function testDashboardRendersEmpty(): void
    {
        $html = $this->renderTemplate('dashboard.twig', [
            'projects' => [],
            'active_jobs' => [],
            'recent_jobs' => [],
        ]);

        $this->assertHtmlNotEmpty($html);
    }

    public function testVersionsPageRenders(): void
    {
        $html = $this->renderTemplate('versions.twig', [
            'versions' => [
                ['id' => 1, 'tag' => '10.2.0', 'major' => 10, 'minor' => 2, 'patch' => 0, 'file_count' => 100, 'symbol_count' => 500],
            ],
            'changes_summary' => [],
            'active_jobs' => [],
        ]);

        $this->assertStringContainsString('10.2.0', $html);
    }

    public function testProjectDetailRenders(): void
    {
        $html = $this->renderTemplate('project-detail.twig', [
            'project' => [
                'id' => 1,
                'name' => 'mymodule',
                'path' => '/tmp/mymodule',
                'project_type' => 'drupal-module',
                'package_name' => 'drupal/mymodule',
                'root_name' => 'mymodule',
                'source_type' => 'local_path',
            ],
            'branches' => [
                ['id' => 1, 'branch_name' => 'main', 'is_default' => 1, 'latest_run' => null],
            ],
            'runs' => [],
            'versions' => [
                ['id' => 1, 'tag' => '10.2.0'],
                ['id' => 2, 'tag' => '11.0.0'],
            ],
        ]);

        $this->assertStringContainsString('mymodule', $html);
        $this->assertStringContainsString('drupal/mymodule', $html);
    }

    public function testJobsPageRenders(): void
    {
        $html = $this->renderTemplate('jobs.twig', [
            'active_jobs' => [],
            'recent_jobs' => [],
        ]);

        $this->assertHtmlNotEmpty($html);
    }

    public function testNewProjectFormRenders(): void
    {
        $html = $this->renderTemplate('project-form.twig');

        $this->assertHtmlNotEmpty($html);
    }
}
