<?php declare(strict_types = 1);

namespace Pepakriz\PHPParallel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use function array_map;
use function explode;
use function implode;
use function rtrim;
use function trim;

class IntegrationTest extends TestCase
{

	public function testEmptyDirectory(): void
	{
		$process = new Process('bin/phpstan-parallel analyse tests/unknown');
		$process->setWorkingDirectory(__DIR__ . '/../../');
		$process->run();

		self::assertStringEqualsFile(__DIR__ . '/empty-directory-snapshot-success.txt', $this->normalizeOutput($process->getOutput()));
		self::assertStringEqualsFile(__DIR__ . '/empty-directory-snapshot-error.txt', $this->normalizeOutput($process->getErrorOutput()));
	}

	public function testSuccess(): void
	{
		$process = new Process('bin/phpstan-parallel analyse tests/Data');
		$process->setWorkingDirectory(__DIR__ . '/../../');
		$process->run();

		self::assertTrue($process->isSuccessful());
		self::assertStringEqualsFile(__DIR__ . '/success-snapshot-success.txt', $this->normalizeOutput($process->getOutput()));
		self::assertStringEqualsFile(__DIR__ . '/success-snapshot-error.txt', $this->normalizeOutput($process->getErrorOutput()));
	}

	public function testError(): void
	{
		$process = new Process('bin/phpstan-parallel analyse tests/ErrorData');
		$process->setWorkingDirectory(__DIR__ . '/../../');
		$process->run();

		self::assertFalse($process->isSuccessful());
		self::assertStringEqualsFile(__DIR__ . '/error-snapshot-success.txt', $this->normalizeOutput($process->getOutput()));
		self::assertStringEqualsFile(__DIR__ . '/error-snapshot-error.txt', $this->normalizeOutput($process->getErrorOutput()));
	}

	private function normalizeOutput(string $output): string
	{
		$rows = explode("\n", $output);
		$rows = array_map(static function (string $row): string {
			return rtrim($row);
		}, $rows);

		return trim(implode("\n", $rows)) . "\n";
	}

}
