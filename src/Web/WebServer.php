<?php

declare(strict_types=1);

namespace DrupalEvolver\Web;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Socket\InternetAddress;
use DrupalEvolver\Project\ManagedProjectService;
use DrupalEvolver\Queue\JobQueue;
use DrupalEvolver\Scanner\RunComparisonService;
use DrupalEvolver\Scanner\ScanRunService;
use DrupalEvolver\Storage\DatabaseApi;
use League\Uri\Http;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use function Amp\delay;
use function Amp\trapSignal;

final class WebServer
{
    private Environment $twig;

    public function __construct(
        private DatabaseApi $api,
        private ManagedProjectService $projects,
        private ScanRunService $scanRuns,
        private RunComparisonService $runComparison,
        private JobQueue $queue,
    ) {
        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        $this->twig = new Environment($loader);
    }

    public function run(string $host = '0.0.0.0', int $port = 8080): void
    {
        $logger = new NullLogger();
        $server = SocketHttpServer::createForDirectAccess($logger);
        $server->expose(new InternetAddress($host, $port));

        $errorHandler = new DefaultErrorHandler();
        $router = $this->buildRouter($server, $errorHandler);

        $server->start($router, $errorHandler);
        trapSignal([\SIGINT, \SIGTERM]);
        $server->stop();
    }

    public function buildRouter(SocketHttpServer $server, DefaultErrorHandler $errorHandler): Router
    {
        $logger = new NullLogger();
        $router = new Router($server, $logger, $errorHandler);
        $router->addRoute('GET', '/', new ClosureRequestHandler($this->handleDashboard(...)));
        $router->addRoute('GET', '/versions', new ClosureRequestHandler($this->handleVersions(...)));
        $router->addRoute('POST', '/versions/index', new ClosureRequestHandler($this->handleQueueIndex(...)));
        $router->addRoute('POST', '/versions/diff', new ClosureRequestHandler($this->handleQueueDiff(...)));
        $router->addRoute('GET', '/projects/new', new ClosureRequestHandler($this->handleNewProjectForm(...)));
        $router->addRoute('POST', '/projects', new ClosureRequestHandler($this->handleCreateProject(...)));
        $router->addRoute('GET', '/projects/{id}', new ClosureRequestHandler($this->handleProjectDetail(...)));
        $router->addRoute('POST', '/projects/{id}/branches', new ClosureRequestHandler($this->handleCreateBranch(...)));
        $router->addRoute('POST', '/projects/{id}/runs', new ClosureRequestHandler($this->handleQueueRun(...)));
        $router->addRoute('GET', '/projects/{id}/compare', new ClosureRequestHandler($this->handleCompareRuns(...)));
        $router->addRoute('GET', '/runs/{id}', new ClosureRequestHandler($this->handleRunDetail(...)));
        $router->addRoute('GET', '/jobs', new ClosureRequestHandler($this->handleJobs(...)));
        $router->addRoute('GET', '/versions/{id}', new ClosureRequestHandler($this->handleVersionDetail(...)));
        $router->addRoute('GET', '/versions/{id}/symbols', new ClosureRequestHandler($this->handleVersionSymbols(...)));
        $router->addRoute('GET', '/versions/{versionId}/symbols/{symbolId}', new ClosureRequestHandler($this->handleVersionSymbolDetail(...)));
        $router->addRoute('GET', '/versions/diff/{from}/{to}', new ClosureRequestHandler($this->handleDiffDetail(...)));
        $router->addRoute('GET', '/jobs/{id}/events', new ClosureRequestHandler($this->handleJobEvents(...)));
        $router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__ . '/../../public'));

        return $router;
    }

    public function handleDashboard(Request $request): Response
    {
        $projects = [];
        foreach ($this->api->projects()->all() as $project) {
            $project['default_branch_record'] = $this->api->projectBranches()->findDefaultForProject((int) $project['id']);
            $project['latest_run'] = $this->api->scanRuns()->findLatestByProject((int) $project['id']);
            $projects[] = $project;
        }

        return $this->html('dashboard.twig', [
            'projects' => $projects,
            'active_jobs' => $this->queue->active(),
            'recent_jobs' => $this->queue->recent(10),
        ]);
    }

    public function handleVersions(Request $request): Response
    {
        $versions = $this->api->versions()->all();
        $changesSummary = $this->api->getChangesSummaryByVersionPair();

        return $this->html('versions.twig', [
            'versions' => $versions,
            'changes_summary' => $changesSummary,
            'active_jobs' => $this->queue->active(),
        ]);
    }

    public function handleVersionDetail(Request $request): Response
    {
        $versionId = $this->routeId($request);
        $version = $this->api->versions()->findById($versionId);
        if ($version === null) {
            return $this->text('Version not found.', HttpStatus::NOT_FOUND);
        }

        $stats = $this->api->db()->query(
            'SELECT symbol_type, COUNT(*) as cnt FROM symbols WHERE version_id = :vid GROUP BY symbol_type ORDER BY cnt DESC',
            ['vid' => $versionId]
        )->fetchAll();

        return $this->html('versions/detail.twig', [
            'version' => $version,
            'stats' => $stats,
        ]);
    }

    public function handleVersionSymbols(Request $request): Response
    {
        $versionId = $this->routeId($request);
        $version = $this->api->versions()->findById($versionId);
        if ($version === null) {
            return $this->text('Version not found.', HttpStatus::NOT_FOUND);
        }

        parse_str($request->getUri()->getQuery(), $query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $type = $query['type'] ?? null;
        $search = $query['search'] ?? null;

        $symbols = $this->api->symbols()->findByVersionPaginated($versionId, $offset, $limit, $type, $search);
        $total = $this->api->symbols()->countByVersionFiltered($versionId, $type, $search);
        $types = $this->api->symbols()->getSymbolTypes($versionId);

        return $this->html('versions/symbols.twig', [
            'version' => $version,
            'symbols' => $symbols,
            'types' => $types,
            'current_type' => $type,
            'search' => $search,
            'pagination' => [
                'current' => $page,
                'total' => (int) ceil($total / $limit),
                'total_records' => $total,
            ],
        ]);
    }

    public function handleVersionSymbolDetail(Request $request): Response
    {
        $versionId = $this->routeId($request, 'versionId');
        $symbolId = $this->routeId($request, 'symbolId');

        $version = $this->api->versions()->findById($versionId);
        if ($version === null) {
            return $this->text('Version not found.', HttpStatus::NOT_FOUND);
        }

        $symbol = $this->api->findSymbolById($symbolId);
        if ($symbol === null || (int) ($symbol['version_id'] ?? 0) !== $versionId) {
            return $this->text('Symbol not found.', HttpStatus::NOT_FOUND);
        }

        $signature = $this->decodeJson($symbol['signature_json'] ?? null);
        $metadata = $this->decodeJson($symbol['metadata_json'] ?? null);
        $links = $this->api->findSemanticLinksForSymbol($symbolId);

        return $this->html('versions/symbol-detail.twig', [
            'version' => $version,
            'symbol' => $symbol,
            'signature' => $signature,
            'metadata' => $metadata,
            'signature_pretty' => $signature !== null ? json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
            'metadata_pretty' => $metadata !== null ? json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
            'semantic_links' => $links,
        ]);
    }

    public function handleDiffDetail(Request $request): Response
    {
        $args = $request->getAttribute(Router::class);
        $fromTag = $args['from'];
        $toTag = $args['to'];

        $from = $this->api->versions()->findByTag($fromTag);
        $to = $this->api->versions()->findByTag($toTag);

        if (!$from || !$to) {
            return $this->text('Version not found.', HttpStatus::NOT_FOUND);
        }

        $changes = $this->api->changes()->findByVersions((int) $from['id'], (int) $to['id']);
        
        // Enhance changes with symbol data if available
        foreach ($changes as &$change) {
            if (!empty($change['old_symbol_id'])) {
                $change['old_symbol'] = $this->api->findSymbolById((int) $change['old_symbol_id']);
            }
            if (!empty($change['new_symbol_id'])) {
                $change['new_symbol'] = $this->api->findSymbolById((int) $change['new_symbol_id']);
            }
        }
        unset($change);

        return $this->html('diff/detail.twig', [
            'from' => $from,
            'to' => $to,
            'changes' => $changes,
        ]);
    }

    public function handleQueueIndex(Request $request): Response
    {
        $form = Form::fromRequest($request);
        $path = trim((string) ($form->getValue('path') ?? ''));
        $tag = trim((string) ($form->getValue('tag') ?? ''));
        $workers = max(1, (int) ($form->getValue('workers') ?? 4));

        if ($path === '' || $tag === '') {
            return $this->text('Path and tag are required.', HttpStatus::BAD_REQUEST);
        }

        $_ = $this->queue->enqueue('index_core', [
            'path' => $path,
            'tag' => $tag,
            'workers' => $workers,
        ]);

        return $this->redirect('/versions');
    }

    public function handleQueueDiff(Request $request): Response
    {
        $form = Form::fromRequest($request);
        $from = trim((string) ($form->getValue('from') ?? ''));
        $to = trim((string) ($form->getValue('to') ?? ''));
        $workers = max(1, (int) ($form->getValue('workers') ?? 4));

        if ($from === '' || $to === '') {
            return $this->text('Both from and to versions are required.', HttpStatus::BAD_REQUEST);
        }

        $_ = $this->queue->enqueue('diff_versions', [
            'from' => $from,
            'to' => $to,
            'workers' => $workers,
        ]);

        return $this->redirect('/versions');
    }

    public function handleNewProjectForm(Request $request): Response
    {
        return $this->html('project-form.twig');
    }

    public function handleCreateProject(Request $request): Response
    {
        $form = Form::fromRequest($request);
        $name = trim((string) ($form->getValue('name') ?? ''));
        $sourceType = trim((string) ($form->getValue('source_type') ?? 'git_remote'));
        $remoteUrl = trim((string) ($form->getValue('remote_url') ?? ''));
        $localPath = trim((string) ($form->getValue('path') ?? ''));
        $defaultBranch = trim((string) ($form->getValue('default_branch') ?? 'main'));
        $type = trim((string) ($form->getValue('type') ?? 'module'));

        if ($name === '') {
            return $this->text('Name is required.', HttpStatus::BAD_REQUEST);
        }

        if ($sourceType === 'local_path') {
            if ($localPath === '') {
                return $this->text('Local path is required.', HttpStatus::BAD_REQUEST);
            }
            $projectId = $this->projects->registerLocalProject($name, $localPath, $defaultBranch, $type !== '' ? $type : null);
        } else {
            if ($remoteUrl === '') {
                return $this->text('Remote URL is required.', HttpStatus::BAD_REQUEST);
            }
            $projectId = $this->projects->registerRemoteProject($name, $remoteUrl, $defaultBranch, $type !== '' ? $type : null);
        }

        return $this->redirect('/projects/' . $projectId);
    }

    public function handleProjectDetail(Request $request): Response
    {
        $projectId = $this->routeId($request);
        $project = $this->api->projects()->findById($projectId);
        if ($project === null) {
            return $this->text('Project not found.', HttpStatus::NOT_FOUND);
        }

        $runs = $this->api->scanRuns()->findByProject($projectId);
        $versions = $this->api->versions()->all();
        $branches = $this->api->projectBranches()->findByProject($projectId);

        foreach ($branches as &$branch) {
            $latestRun = null;
            foreach ($runs as $run) {
                if ($run['branch_name'] === $branch['branch_name']) {
                    $latestRun = $run;
                    break;
                }
            }
            $branch['latest_run'] = $latestRun;
        }
        unset($branch);

        return $this->html('project-detail.twig', [
            'project' => $project,
            'branches' => $branches,
            'runs' => $runs,
            'versions' => $versions,
        ]);
    }

    public function handleCreateBranch(Request $request): Response
    {
        $projectId = $this->routeId($request);
        $project = $this->api->projects()->findById($projectId);
        if ($project === null) {
            return $this->text('Project not found.', HttpStatus::NOT_FOUND);
        }

        $form = Form::fromRequest($request);
        $branchName = trim((string) ($form->getValue('branch_name') ?? ''));
        $isDefault = (string) ($form->getValue('is_default') ?? '') !== '';

        if ($branchName === '') {
            return $this->text('Branch name is required.', HttpStatus::BAD_REQUEST);
        }

        $_ = $this->projects->addBranch($projectId, $branchName, $isDefault);

        return $this->redirect('/projects/' . $projectId);
    }

    public function handleQueueRun(Request $request): Response
    {
        $projectId = $this->routeId($request);
        $project = $this->api->projects()->findById($projectId);
        if ($project === null) {
            return $this->text('Project not found.', HttpStatus::NOT_FOUND);
        }

        $form = Form::fromRequest($request);
        $branchName = trim((string) ($form->getValue('branch_name') ?? ''));
        $targetCoreVersion = trim((string) ($form->getValue('target_core_version') ?? ''));
        $fromCoreVersion = trim((string) ($form->getValue('from_core_version') ?? ''));
        $workers = max(1, (int) ($form->getValue('workers') ?? 1));

        if ($branchName === '' || $targetCoreVersion === '') {
            return $this->text('Branch and target version are required.', HttpStatus::BAD_REQUEST);
        }

        $runId = $this->scanRuns->queueBranchScan(
            $projectId,
            $branchName,
            $targetCoreVersion,
            $fromCoreVersion !== '' ? $fromCoreVersion : null,
            $workers
        );

        return $this->redirect('/runs/' . $runId);
    }

    public function handleRunDetail(Request $request): Response
    {
        $runId = $this->routeId($request);
        $run = $this->api->scanRuns()->findById($runId);
        if ($run === null) {
            return $this->text('Scan run not found.', HttpStatus::NOT_FOUND);
        }

        $project = $this->api->projects()->findById((int) $run['project_id']);
        if ($project === null) {
            return $this->text('Project not found.', HttpStatus::NOT_FOUND);
        }

        $matches = $this->api->findMatchesWithChangesForRun($runId);
        $logs = [];
        if (!empty($run['job_id'])) {
            $logs = array_reverse($this->api->jobLogs()->findByJob((int) $run['job_id']));
        }

        return $this->html('run-detail.twig', [
            'project' => $project,
            'run' => $run,
            'summary' => $this->api->summarizeScanRun($runId),
            'matches' => $matches,
            'logs' => $logs,
        ]);
    }

    public function handleCompareRuns(Request $request): Response
    {
        $projectId = $this->routeId($request);
        $project = $this->api->projects()->findById($projectId);
        if ($project === null) {
            return $this->text('Project not found.', HttpStatus::NOT_FOUND);
        }

        parse_str($request->getUri()->getQuery(), $query);
        $baseRunId = isset($query['base_run']) ? (int) $query['base_run'] : 0;
        $headRunId = isset($query['head_run']) ? (int) $query['head_run'] : 0;
        $comparison = null;
        $error = null;

        if ($baseRunId > 0 && $headRunId > 0) {
            try {
                $comparison = $this->runComparison->compare($baseRunId, $headRunId);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->html('run-compare.twig', [
            'project' => $project,
            'runs' => $this->api->scanRuns()->findByProject($projectId),
            'comparison' => $comparison,
            'error' => $error,
            'base_run_id' => $baseRunId,
            'head_run_id' => $headRunId,
        ]);
    }

    public function handleJobs(Request $request): Response
    {
        return $this->html('jobs.twig', [
            'active_jobs' => $this->queue->active(),
            'recent_jobs' => $this->queue->recent(50),
        ]);
    }

    public function handleJobEvents(Request $request): Response
    {
        $jobId = $this->routeId($request);
        $stream = new ReadableIterableStream((function () use ($jobId): \Generator {
            $lastSeq = 0;
            $lastSnapshot = null;

            while (true) {
                $job = $this->api->jobs()->findById($jobId);
                if ($job === null) {
                    yield $this->sse('error', ['message' => 'Job not found']);
                    break;
                }

                $snapshot = [
                    'id' => (int) $job['id'],
                    'kind' => (string) $job['kind'],
                    'status' => (string) $job['status'],
                    'progress_current' => (int) ($job['progress_current'] ?? 0),
                    'progress_total' => (int) ($job['progress_total'] ?? 0),
                    'progress_label' => (string) ($job['progress_label'] ?? ''),
                    'error_message' => $job['error_message'] ?? null,
                ];

                if ($snapshot !== $lastSnapshot) {
                    yield $this->sse('job', $snapshot);
                    $lastSnapshot = $snapshot;
                }

                foreach ($this->api->jobLogs()->findAfterSeq($jobId, $lastSeq) as $log) {
                    $lastSeq = (int) $log['seq'];
                    yield $this->sse('log', [
                        'seq' => (int) $log['seq'],
                        'level' => (string) $log['level'],
                        'message' => (string) $log['message'],
                        'created_at' => (string) $log['created_at'],
                    ]);
                }

                if (in_array($snapshot['status'], ['completed', 'failed'], true)) {
                    $run = $this->api->scanRuns()->findByJobId($jobId);
                    if ($run !== null) {
                        yield $this->sse('run_complete', [
                            'run_id' => (int) $run['id'],
                            'status' => (string) $run['status'],
                        ]);
                    }
                    break;
                }

                yield ": ping\n\n";
                delay(0.5);
            }
        })());

        return new Response(
            HttpStatus::OK,
            [
                'content-type' => 'text/event-stream; charset=utf-8',
                'cache-control' => 'no-cache',
                'x-accel-buffering' => 'no',
            ],
            $stream
        );
    }

    private function html(string $template, array $context = [], int $status = HttpStatus::OK): Response
    {
        return new Response(
            $status,
            ['content-type' => 'text/html; charset=utf-8'],
            $this->twig->render($template, $context)
        );
    }

    private function text(string $text, int $status = HttpStatus::OK): Response
    {
        return new Response($status, ['content-type' => 'text/plain; charset=utf-8'], $text);
    }

    private function redirect(string $location): Response
    {
        return new Response(HttpStatus::SEE_OTHER, ['location' => $location], '');
    }

    private function routeId(Request $request, string $key = 'id'): int
    {
        $args = $request->getAttribute(Router::class);
        if (!is_array($args) || !isset($args[$key])) {
            throw new \InvalidArgumentException(sprintf('Missing route parameter: %s.', $key));
        }

        return (int) $args[$key];
    }

    private function sse(string $event, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return "event: {$event}\ndata: {$json}\n\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
