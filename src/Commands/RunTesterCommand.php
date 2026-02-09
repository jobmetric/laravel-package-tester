<?php

namespace JobMetric\PackageTester\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;

class RunTesterCommand extends Command
{
    protected const STATUS_SUCCESS = 'success';
    protected const STATUS_FAILURE = 'failure';
    protected const STATUS_WARNING = 'warning';
    protected const STATUS_ERROR = 'error';
    protected const STATUS_NOT_INSTALLED = 'not_installed';
    protected const STATUS_TEST_FILE_NOT_FOUND = 'test_file_not_found';
    protected const STATUS_INVALID_JSON = 'invalid_json';

    /**
     * Discovered packages cache
     *
     * @var array|null
     */
    protected ?array $discoveredPackages = null;
    protected ?array $selectablePackages = null;

    /**
     * Command signature
     */
    protected $signature = 'test-run 
                            {package? : The full package name (e.g., jobmetric/laravel-flow)}
                            {--all : Test all discovered packages}
                            {--detailed : Show detailed information}
                            {--filter= : PHPUnit --filter pattern}
                            {--stop-on-failure : PHPUnit --stop-on-failure}
                            {--testdox : PHPUnit --testdox (human-readable test output)}
                            {--options=* : Additional raw phpunit options (repeatable)}
                            {--continue-on-failure : Continue testing other packages even if one fails}';

    protected $description = 'Test Laravel packages from composer plugin JSON config and run PHPUnit';

    protected function configure(): void
    {
        parent::configure();

        $this->setAliases(['package-tester:test-run', 'package-tester:run']);
    }

    /**
     * Handle command
     */
    public function handle(): int
    {
        $this->info('🔍 Laravel Package Tester');
        $this->newLine();

        $packageName = $this->argument('package');
        $testAll = $this->option('all');
        $verbose = $this->option('detailed');

        if ($verbose) {
            $this->logDiscoveredPackages();
        }

        if ($testAll) {
            return $this->testAllPackages($verbose);
        }

        if ($packageName) {
            $result = $this->testPackage($packageName, $verbose);

            return $this->getExitCodeFromResult($result);
        }

        return $this->showInteractiveMenu($verbose);
    }

    /**
     * Log discovered packages
     */
    protected function logDiscoveredPackages(): void
    {
        $packages = $this->getPackages();

        if ($packages->isEmpty()) {
            $this->warn('No packages discovered. Check your package-tester JSON config.');
            $this->newLine();

            return;
        }

        $this->info('Discovered packages:');
        foreach ($packages as $pkg) {
            $this->line(" - {$pkg['name']} ({$pkg['version']})");
        }
        $this->newLine();
    }

    /**
     * Show interactive menu
     */
    protected function showInteractiveMenu(bool $verbose): int
    {
        $packages = $this->getSelectablePackages();

        if ($packages->isEmpty()) {
            $this->error('No packages discovered. Make sure the package-tester JSON exists.');
            return SymfonyCommand::FAILURE;
        }

        $testerJsonPackages = $this->findPackagesWithTesterJson($packages);
        if (!empty($testerJsonPackages)) {
            $this->info('Packages with package-tester.json:');
            foreach ($testerJsonPackages as $name) {
                $this->line(" - {$name}");
            }
            $this->newLine();
        }

        $this->info('Available packages:');
        $this->newLine();

        $choices = $packages->map(function ($pkg, $idx) {
            $name = $pkg['name'] ?? 'unknown';
            $version = $pkg['version'] ?? 'N/A';
            $testerJsonFlag = $this->packageHasTesterConfig($pkg) ? ' [package-tester.json]' : '';

            return ($idx + 1) . '. ' . $name . ' (' . $version . ')' . $testerJsonFlag;
        })
            ->prepend('All packages')
            ->values()
            ->toArray();

        foreach ($choices as $i => $choice) {
            $this->line(" [$i] $choice");
        }

        $this->newLine();
        $input = (string) $this->ask('Select a package to test', '0');
        $selectedIndex = $this->parseMenuSelection($input);

        if ($selectedIndex < 0 || $selectedIndex >= count($choices)) {
            $selectedIndex = 0;
        }

        $selected = $choices[$selectedIndex];

        if ($selected === 'All packages') {
            return $this->testPackageCollection($packages, $verbose);
        }

        $pkgIndex = $selectedIndex - 1;
        $packageName = $packages->values()[$pkgIndex]['name'] ?? null;

        if (!$packageName) {
            return $this->testAllPackages($verbose);
        }

        $result = $this->testPackage($packageName, $verbose);

        return $this->getExitCodeFromResult($result);
    }

    /**
     * Test all discovered packages
     */
    protected function testAllPackages(bool $verbose): int
    {
        $packages = $this->getPackages();

        return $this->testPackageCollection($packages, $verbose);
    }

    protected function testPackageCollection(Collection $packages, bool $verbose): int
    {
        if ($packages->isEmpty()) {
            $this->error('No packages discovered.');

            return SymfonyCommand::FAILURE;
        }

        $this->info("Testing {$packages->count()} package(s)...");
        $this->newLine();

        $continueOnFailure = $this->option('continue-on-failure');
        $overallExit = 0;

        foreach ($packages as $pkg) {
            $result = $this->testPackage($pkg['name'], $verbose, false);
            $exitCode = $this->getExitCodeFromResult($result);

            if ($exitCode !== SymfonyCommand::SUCCESS) {
                $overallExit = $exitCode;

                if (!$continueOnFailure) {
                    $this->newLine();
                    $this->error('Stopping execution due to failure.');

                    return $exitCode;
                }
            }
        }

        return $overallExit === 0 ? SymfonyCommand::SUCCESS : $overallExit;
    }

    /**
     * Test a specific package
     */
    protected function testPackage(string $packageName, bool $verbose, bool $showHeader = true): array
    {
        $packages = $this->getSelectablePackages();
        $discovered = $packages->firstWhere('name', $packageName);

        if (!$discovered) {
            $this->error("Package '{$packageName}' not discovered!");
            return ['name' => $packageName, 'status' => self::STATUS_NOT_INSTALLED];
        }

        if ($showHeader) {
            $this->info("Testing package: <fg=cyan>{$packageName}</>");
            $this->newLine();
        }

        $packagePath = $discovered['path'] ?? null;
        $tests = (array) ($discovered['tests'] ?? []);

        if (!is_string($packagePath) || $packagePath === '') {
            $this->error("Invalid or missing package path for '{$packageName}'");

            return [
                'name' => $discovered['name'],
                'version' => $discovered['version'] ?? 'N/A',
                'status' => self::STATUS_ERROR,
            ];
        }

        if (empty($tests)) {
            $this->warn("No tests configured for '{$packageName}'");
            return ['name' => $packageName, 'status' => self::STATUS_WARNING];
        }

        $results = [
            'name' => $discovered['name'],
            'version' => $discovered['version'] ?? 'N/A',
            'status' => self::STATUS_SUCCESS,
            'components' => [],
        ];

        $testResult = $this->runPackageTestSuites($packagePath, $tests, $verbose);
        $results['components']['tests'] = $testResult;

        if (($testResult['status'] ?? null) === self::STATUS_FAILURE || ($testResult['exit_code'] ?? 0) !== 0) {
            $results['status'] = self::STATUS_FAILURE;
        } elseif (($testResult['status'] ?? null) === self::STATUS_WARNING) {
            $results['status'] = self::STATUS_WARNING;
        }

        if ($showHeader) {
            $this->displayPackageResults($results);
        }

        return $results;
    }

    /**
     * Run multiple test suites for a package
     */
    protected function runPackageTestSuites(string $packagePath, array $tests, bool $verbose): array
    {
        $overallExit = 0;
        $overallStatus = self::STATUS_SUCCESS;

        foreach ($tests as $test) {
            if (is_string($test)) {
                $test = ['path' => $test];
            }

            $suite = [
                'path' => $test['path'] ?? 'tests',
                'options' => $test['options'] ?? $test['option'] ?? [],
                'filter' => $test['filter'] ?? null,
            ];

            $result = $this->runPackageTests($packagePath, $suite, $verbose);

            if (($result['exit_code'] ?? 0) !== 0) {
                $overallExit = $result['exit_code'];
                $overallStatus = self::STATUS_FAILURE;
            } elseif (($result['status'] ?? null) === self::STATUS_WARNING && $overallStatus === self::STATUS_SUCCESS) {
                $overallStatus = self::STATUS_WARNING;
            }
        }

        return [
            'status' => $overallStatus,
            'exit_code' => $overallExit,
        ];
    }

    /**
     * Run PHPUnit for a single test suite
     */
    protected function runPackageTests(string $packagePath, array $suite, bool $verbose): array
    {
        $phpunitCommand = $this->resolvePhpUnitCommand();

        if (!$phpunitCommand) {
            return ['status' => self::STATUS_ERROR, 'message' => 'PHPUnit binary not found', 'exit_code' => 1];
        }

        $testPath = $suite['path'] ?? 'tests';
        $fullPath = $packagePath . '/' . ltrim($testPath, '/');

        if (!File::exists($fullPath)) {
            $this->warn("Test path not found: {$fullPath}");

            return [
                'status' => self::STATUS_TEST_FILE_NOT_FOUND,
                'exit_code' => SymfonyCommand::FAILURE,
                'path' => $fullPath,
            ];
        }

        $cmd = $this->buildPhpUnitCommand($phpunitCommand, $suite);
        $cmd[] = $fullPath;

        if ($verbose) {
            $this->newLine();
            $this->info(str_repeat('=', 12) . " Running PHPUnit Tests " . str_repeat('=', 12));
            $this->comment('$ ' . $this->prettyCommand($cmd));
            $this->newLine();
        }

        $exitCode = $this->runStreaming($cmd);

        if ($verbose) {
            $this->newLine();
            $status = $exitCode === 0 ? 'SUCCESS' : 'FAILED';
            $this->info(str_repeat('-', 10) . " PHPUnit finished: {$status} (exit: {$exitCode}) " . str_repeat('-', 10));
        }

        return [
            'status' => $exitCode === 0 ? self::STATUS_SUCCESS : self::STATUS_FAILURE,
            'exit_code' => $exitCode,
            'path' => $fullPath,
        ];
    }

    protected function buildPhpUnitCommand(array $phpunitCommand, array $suite): array
    {
        $options = (array) ($suite['option'] ?? $suite['options'] ?? []);
        $filter = $suite['filter'] ?? null;

        $cmd = array_merge($phpunitCommand, $options);

        $cliFilter = $this->option('filter');
        $finalFilter = $cliFilter ?: $filter;

        if ($finalFilter) {
            $cmd[] = '--filter';
            $cmd[] = $finalFilter;
        }

        if ($this->option('stop-on-failure')) {
            $cmd[] = '--stop-on-failure';
        }

        if ($this->option('testdox')) {
            $cmd[] = '--testdox';
        }

        $extraOptions = (array) $this->option('options');
        return array_merge($cmd, $extraOptions);
    }

    protected function resolvePhpUnitCommand(): ?array
    {
        $override = env('PHPUNIT_BINARY');
        if ($override && is_file($override)) {
            return [PHP_BINARY, $override];
        }

        $bat = base_path('vendor/bin/phpunit.bat');
        if (DIRECTORY_SEPARATOR === '\\' && is_file($bat)) {
            return [$bat];
        }

        $shim = base_path('vendor/bin/phpunit');
        if (is_file($shim) && is_executable($shim)) {
            return [$shim];
        }

        $direct = base_path('vendor/phpunit/phpunit/phpunit');
        if (is_file($direct)) {
            return [PHP_BINARY, $direct];
        }

        return null;
    }

    protected function runStreaming(array $cmd): int
    {
        $process = new Process($cmd, base_path());
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->run(fn($type, $buffer) => print($buffer));

        return $process->getExitCode() ?? 1;
    }

    protected function prettyCommand(array $cmd): string
    {
        return implode(' ', array_map(fn($p) => preg_match('/\s/', $p) ? '"' . $p . '"' : $p, $cmd));
    }

    protected function displayPackageResults(array $results): void
    {
        $components = $results['components'] ?? [];

        if (isset($components['tests'])) {
            $tests = $components['tests'];
            $this->table(
                ['Component', 'Status', 'Exit Code'],
                [['PHPUnit Tests', $this->getStatusIcon($tests['status'] ?? 'unknown'), $tests['exit_code'] ?? 0]]
            );
        } else {
            $this->warn('No tests configured for this package.');
        }
    }

    protected function getStatusIcon(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => '<fg=green>✓</>',
            self::STATUS_NOT_INSTALLED, self::STATUS_TEST_FILE_NOT_FOUND, self::STATUS_INVALID_JSON, self::STATUS_ERROR, self::STATUS_FAILURE => '<fg=red>✗</>',
            default => '<fg=yellow>⚠</>',
        };
    }

    protected function getExitCodeFromResult(array $result): int
    {
        if (isset($result['components']['tests']['exit_code'])) {
            $exitCode = $result['components']['tests']['exit_code'];
            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        if (isset($result['status']) && in_array($result['status'], [
            self::STATUS_NOT_INSTALLED,
            self::STATUS_TEST_FILE_NOT_FOUND,
            self::STATUS_INVALID_JSON,
            self::STATUS_ERROR,
            self::STATUS_FAILURE
        ])) {
            return SymfonyCommand::FAILURE;
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Read JSON config file and return packages collection
     */
    protected function getPackages(): Collection
    {
        if ($this->discoveredPackages !== null) {
            return collect($this->discoveredPackages);
        }

        $jsonPath = base_path('.package-tester/config.json');

        if (!File::exists($jsonPath)) {
            $this->discoveredPackages = [];
            return collect();
        }

        $json = json_decode(File::get($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in ' . $jsonPath . ': ' . json_last_error_msg());
            $this->discoveredPackages = [];
            return collect();
        }

        $normalized = [];

        foreach ($json as $packageName => $package) {
            if (!is_array($package)) {
                continue;
            }

            if (!isset($package['name']) || !is_string($package['name']) || $package['name'] === '') {
                if (is_string($packageName) && $packageName !== '') {
                    $package['name'] = $packageName;
                }
            }

            if (!isset($package['version']) || !is_string($package['version']) || $package['version'] === '') {
                $package['version'] = 'N/A';
            }

            $normalized[] = $package;
        }

        $this->discoveredPackages = $normalized;
        return collect($this->discoveredPackages);
    }

    protected function getSelectablePackages(): Collection
    {
        if ($this->selectablePackages !== null) {
            return collect($this->selectablePackages);
        }

        $discoveredPackages = $this->getPackages()
            ->filter(fn($package) => is_array($package) && !empty($package['name']))
            ->keyBy('name');

        $vendorTesterJsonFiles = array_merge(
            File::glob(base_path('vendor/*/*/package-tester.json')),
            File::glob(base_path('vendor/*/package-tester.json'))
        );

        foreach ($vendorTesterJsonFiles as $testerJsonPath) {
            $package = $this->buildPackageFromTesterJson($testerJsonPath);
            if ($package === null) {
                continue;
            }

            $name = $package['name'];
            if (!$discoveredPackages->has($name)) {
                $discoveredPackages->put($name, $package);
                continue;
            }

            $existing = $discoveredPackages->get($name);
            $discoveredPackages->put($name, $this->mergePackageMeta($existing, $package));
        }

        $this->selectablePackages = $discoveredPackages->values()->all();

        return collect($this->selectablePackages);
    }

    protected function buildPackageFromTesterJson(string $testerJsonPath): ?array
    {
        if (!File::exists($testerJsonPath)) {
            return null;
        }

        $name = $this->inferPackageNameFromTesterPath($testerJsonPath);
        if ($name === null) {
            return null;
        }

        $decoded = json_decode(File::get($testerJsonPath), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $namespace = $decoded['namespace'] ?? [];
        if (!is_array($namespace)) {
            $namespace = [];
        }

        $tests = [[
            'path' => $namespace['path'] ?? 'tests',
            'options' => $namespace['options'] ?? $namespace['option'] ?? [],
            'filter' => $namespace['filter'] ?? null,
        ]];

        return [
            'name' => $name,
            'version' => 'N/A',
            'path' => dirname($testerJsonPath),
            'tests' => $tests,
            'package_tester_config' => $testerJsonPath,
        ];
    }

    protected function mergePackageMeta(array $primary, array $fallback): array
    {
        $merged = array_replace($fallback, $primary);

        if ((empty($merged['tests']) || !is_array($merged['tests'])) && !empty($fallback['tests'])) {
            $merged['tests'] = $fallback['tests'];
        }

        if ((empty($merged['path']) || !is_string($merged['path'])) && !empty($fallback['path'])) {
            $merged['path'] = $fallback['path'];
        }

        if ((empty($merged['package_tester_config']) || !is_string($merged['package_tester_config'])) && !empty($fallback['package_tester_config'])) {
            $merged['package_tester_config'] = $fallback['package_tester_config'];
        }

        return $merged;
    }

    protected function packageHasTesterConfig(array $package): bool
    {
        $configPath = $package['package_tester_config'] ?? null;
        if (is_string($configPath) && $configPath !== '' && File::exists($configPath)) {
            return true;
        }

        $packagePath = $package['path'] ?? null;
        if (!is_string($packagePath) || $packagePath === '') {
            return false;
        }

        $testerConfigPath = rtrim($packagePath, '/\\') . DIRECTORY_SEPARATOR . 'package-tester.json';

        return File::exists($testerConfigPath);
    }

    protected function findPackagesWithTesterJson(Collection $discoveredPackages): array
    {
        $names = [];

        foreach ($discoveredPackages as $package) {
            if (!is_array($package) || !$this->packageHasTesterConfig($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        $vendorTesterJsonFiles = array_merge(
            File::glob(base_path('vendor/*/*/package-tester.json')),
            File::glob(base_path('vendor/*/package-tester.json'))
        );

        foreach ($vendorTesterJsonFiles as $testerJsonFile) {
            $name = $this->inferPackageNameFromTesterPath($testerJsonFile);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    protected function inferPackageNameFromTesterPath(string $testerJsonPath): ?string
    {
        $normalizedPath = str_replace('\\', '/', $testerJsonPath);
        $vendorPath = rtrim(str_replace('\\', '/', base_path('vendor')), '/') . '/';

        if (!str_starts_with($normalizedPath, $vendorPath)) {
            return null;
        }

        $relativePath = substr($normalizedPath, strlen($vendorPath));
        $segments = explode('/', $relativePath);

        if (count($segments) >= 3 && end($segments) === 'package-tester.json') {
            return $segments[0] . '/' . $segments[1];
        }

        if (count($segments) >= 2 && end($segments) === 'package-tester.json') {
            return $segments[0];
        }

        return null;
    }

    protected function parseMenuSelection(string $input): int
    {
        $normalized = strtr(trim($input), [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);

        if ($normalized === '' || !preg_match('/^-?\d+$/', $normalized)) {
            return 0;
        }

        return (int) $normalized;
    }
}
