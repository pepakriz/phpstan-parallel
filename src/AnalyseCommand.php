<?php declare(strict_types = 1);

namespace Pepakriz\PHPParallel;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use PHPStan\Analyser\Error;
use PHPStan\Command\AnalyseCommand as PHPStanAnalyseCommand;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\ErrorsConsoleStyle;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileHelper;
use PHPStan\ShouldNotHappenException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use function array_chunk;
use function array_map;
use function array_merge;
use function ceil;
use function count;
use function explode;
use function file_exists;
use function getcwd;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function sprintf;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function usleep;
use const DIRECTORY_SEPARATOR;

class AnalyseCommand extends PHPStanAnalyseCommand
{

	private const OPTION_PROCESSES = 'processes';

	protected function configure(): void
	{
		parent::configure();

		$this->addOption(self::OPTION_PROCESSES, 'p', InputOption::VALUE_OPTIONAL, 'The number of test processes to run (default 5)', '5');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
		$consoleStyle = new ErrorsConsoleStyle($input, $output);

		$paths = $input->getArgument('paths');
		$projectConfigFile = $input->getOption('configuration');
		$levelOption = $input->getOption(self::OPTION_LEVEL);
		$noProgress = $input->getOption(ErrorsConsoleStyle::OPTION_NO_PROGRESS);
		$autoloadFile = $input->getOption('autoload-file');
		$errorFormat = $input->getOption('error-format');
		$oldErrorFormat = $input->getOption('errorFormat');
		$processes = $input->getOption(self::OPTION_PROCESSES);

		if (!is_string($processes)) {
			throw new ShouldNotHappenException();
		}

		if ($levelOption !== null && !is_string($levelOption)) {
			throw new ShouldNotHappenException();
		}

		if ($projectConfigFile !== null && !is_string($projectConfigFile)) {
			throw new ShouldNotHappenException();
		}

		if (!is_bool($noProgress)) {
			throw new ShouldNotHappenException();
		}

		if (!is_array($paths)) {
			throw new ShouldNotHappenException();
		}

		if (!is_string($errorFormat)) {
			throw new ShouldNotHappenException();
		}

		if (is_string($oldErrorFormat)) {
			$errOutput->writeln('Note: Using the option --errorFormat is deprecated. Use --error-format instead.');
			$errorFormat = $oldErrorFormat;
		}

		$processes = (int) $processes;

		$currentWorkingDirectory = getcwd();
		if ($currentWorkingDirectory === false) {
			throw new ShouldNotHappenException();
		}
		$fileHelper = new FileHelper($currentWorkingDirectory);

		if ($projectConfigFile === null) {
			foreach (['phpstan.neon', 'phpstan.neon.dist'] as $discoverableConfigName) {
				$discoverableConfigFile = $currentWorkingDirectory . DIRECTORY_SEPARATOR . $discoverableConfigName;
				if (is_file($discoverableConfigFile)) {
					$projectConfigFile = $discoverableConfigFile;
					$errOutput->writeln(sprintf('Note: Using configuration file %s.', $projectConfigFile));
					break;
				}
			}
		}

		// Container
		$containerFactory = new ContainerFactory($currentWorkingDirectory);
		$additionalConfigFiles = [];
		if ($levelOption !== null) {
			$levelConfigFile = sprintf('%s/config.level%s.neon', $containerFactory->getConfigDirectory(), $levelOption);
			if (!is_file($levelConfigFile)) {
				$errOutput->writeln(sprintf('Level config file %s was not found.', $levelConfigFile));
				return 1;
			}

			$additionalConfigFiles[] = $levelConfigFile;
		}

		if ($projectConfigFile !== null) {
			$additionalConfigFiles[] = $projectConfigFile;
		}

		$tmpDir = sys_get_temp_dir() . '/phpstan-parallel';
		if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
			$errOutput->writeln(sprintf('Cannot create a temp directory %s', $tmpDir));
			return 1;
		}

		$paths = array_map(static function (string $path) use ($fileHelper): string {
			return $fileHelper->absolutizePath($path);
		}, $paths);

		$container = $containerFactory->create($tmpDir, $additionalConfigFiles);
		$fileExtensions = $container->parameters['fileExtensions'];

		$files = [];
		/** @var Error[] $fileSpecificErrors */
		$fileSpecificErrors = [];
		/** @var string[] $nonFileSpecificErrors */
		$nonFileSpecificErrors = [];
		foreach ($paths as $path) {
			if (!file_exists($path)) {
				$fileSpecificErrors[] = new Error(sprintf('<error>Path %s does not exist</error>', $path), $path, null, false);
			} elseif (is_file($path)) {
				$files[] = $fileHelper->normalizePath($path);
			} else {
				$finder = new Finder();
				$finder->followLinks();
				foreach ($finder->files()->name('*.{' . implode(',', $fileExtensions) . '}')->in($path) as $fileInfo) {
					$files[] = $fileHelper->normalizePath($fileInfo->getPathname());
				}
			}
		}

		$fileCounter = count($files);
		$fileChunks = $fileCounter > 0 ? array_chunk($files, (int) ceil($fileCounter / $processes)) : [];
		$consoleStyle->progressStart($fileCounter);

		/** @var Process[] $processes */
		$processes = [];
		/** @var int[] $processProgresses */
		$processProgresses = [];
		foreach ($fileChunks as $fileChunk) {
			$cmd = [];
			$cmd[] = "$currentWorkingDirectory/vendor/phpstan/phpstan/bin/phpstan";
			$cmd[] = 'analyse';
			$cmd[] = '--configuration';
			$cmd[] = $projectConfigFile;

			if ($noProgress) {
				$cmd[] = '--no-progress';
			}

			if ($autoloadFile !== null) {
				$cmd[] = '--autoload-file';
				$cmd[] = $autoloadFile;
			}

			$cmd[] = '--level';
			$cmd[] = $levelOption ?? '0';
			$cmd[] = '--error-format';
			$cmd[] = 'json';
			$cmd = array_merge($cmd, $fileChunk);

			$process = new Process($cmd, $currentWorkingDirectory);
			$process->start();

			$processes[] = $process;
			$processProgresses[] = 0;
		}

		/** @var AnalysisResult[] $analysisResults */
		$analysisResults = [];
		while (true) {
			foreach ($processes as $key => $runningProcess) {
				$errorOutput = $runningProcess->getIncrementalErrorOutput();
				if ($errorOutput !== '') {
					$processProgress = $this->parseProgressFromProcess($errorOutput);
					$consoleStyle->progressAdvance($processProgress - $processProgresses[$key]);
					$processProgresses[$key] = $processProgress;
				}

				if ($runningProcess->isRunning()) {
					continue;
				}

				$analysisResults[] = $this->parseAnalysisResultFromProcess($runningProcess);
				unset($processes[$key]);
			}

			if (count($processes) === 0) {
				break;
			}

			usleep(100);
		}

		$consoleStyle->progressFinish();

		foreach ($analysisResults as $analysisResult) {
			$fileSpecificErrors = array_merge($fileSpecificErrors, $analysisResult->getFileSpecificErrors());
			$nonFileSpecificErrors = array_merge($nonFileSpecificErrors, $analysisResult->getNotFileSpecificErrors());
		}

		$analysisResult = new AnalysisResult($fileSpecificErrors, $nonFileSpecificErrors, false, $currentWorkingDirectory);
		$errorFormatterServiceName = sprintf('errorFormatter.%s', $errorFormat);
		if (!$container->hasService($errorFormatterServiceName)) {
			$errOutput->writeln(sprintf(
				'Error formatter "%s" not found. Available error formatters are: %s',
				$errorFormat,
				implode(', ', array_map(static function (string $name) {
					return substr($name, strlen('errorFormatter.'));
				}, $container->findByType(ErrorFormatter::class)))
			));
			return 1;
		}
		/** @var ErrorFormatter $errorFormatter */
		$errorFormatter = $container->getService($errorFormatterServiceName);

		return $errorFormatter->formatErrors($analysisResult, $consoleStyle);
	}

	private function parseProgressFromProcess(string $data): int
	{
		$number = explode('/', trim($data));
		return (int) $number[0];
	}

	private function parseAnalysisResultFromProcess(Process $process): AnalysisResult
	{
		$data = $process->getOutput();
		try {
			$data = Json::decode($data, Json::FORCE_ARRAY);
		} catch (JsonException $e) {
			if ($data === '') {
				$data = $process->getErrorOutput();
			}

			return new AnalysisResult([], ['Unexpected output from subprocess: ' . $data], false, '');
		}

		$fileSpecificErrors = [];
		foreach ($data['files'] as $file => $errors) {
			foreach ($errors['messages'] as $message) {
				$fileSpecificErrors[] = new Error($message['message'], $file, $message['line'], $message['ignorable']);
			}
		}

		return new AnalysisResult($fileSpecificErrors, $data['errors'], false, '');
	}

}
