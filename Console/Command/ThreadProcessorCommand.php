<?php
/**
 * Copyright © Username, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Console\Command;

use Exception;
use InvalidArgumentException;
use Magento\Framework\Console\Cli;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

class ThreadProcessorCommand extends Command
{
    /** @var string */
    public const BINARY_MAGENTO = 'bin/magento';

    /** @var Process */
    private $currentProcess = null;

    /** @var bool */
    private bool $isAllowedToRun = true;

    /** @var bool */
    private bool $pcntlEnabled = false;

    protected function configure(): void
    {
        $this->setDescription('Wrapper command to run a command line indefinitely in a dedicated thread');
        $this->setName(
            'thread:processor'
        )->addArgument(
            'command_name',
            InputArgument::REQUIRED,
            'The name of the command to be started.'
        )->addArgument(
            'command_args',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Arguments passed to the wrapped command'
        )->addOption(
            'timeout',
            '',
            InputOption::VALUE_OPTIONAL,
            'Define the process timeout in seconds'
        )->addOption(
            'iterations',
            '',
            InputOption::VALUE_OPTIONAL,
            'Define the number of iteration'
        )->addOption(
            'delay',
            '',
            InputOption::VALUE_OPTIONAL,
            'Define the delay in ms between each iteration'
        )->addOption(
            'environment',
            'env',
            InputOption::VALUE_OPTIONAL,
            'Set environment variables separate by comma'
        )->addOption(
            'progress',
            'p',
            InputOption::VALUE_NONE,
            'Show progress bar while executing command'
        )->addOption(
            'fail-on-loop',
            '',
            InputOption::VALUE_NONE,
            'Stop iteration loop after the first failed iteration'
        )->addOption(
            'ignore-exit-code',
            '',
            InputOption::VALUE_NONE,
            'Always return success exit code even when some iterations fail'
        );

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Register signal handlers before any processing
        $this->registerSignalHandlers();

        try {
            // Argument and option
            $rawCommandName = trim((string)$input->getArgument('command_name'));
            if ($rawCommandName === '') {
                throw new InvalidArgumentException('command_name cannot be empty');
            }
            $commandArgs = $this->getCommandArgs($rawCommandName, (array)$input->getArgument('command_args'));

            $environment = $input->getOption('environment');
            $envExploded = !empty($environment) ? explode(',', $environment) : null;
            $timeout = (float)$input->getOption('timeout') ?: 300;
            $iterations = (int)$input->getOption('iterations');
            $delay = (int)$input->getOption('delay');
            $failOnLoop = (bool)$input->getOption('fail-on-loop');
            $ignoreExitCode = (bool)$input->getOption('ignore-exit-code');
            $this->validateOptions($timeout, $iterations, $delay);
        } catch (InvalidArgumentException $e) {
            $output->writeln($e->getMessage());
            return Cli::RETURN_FAILURE;
        }

        // Build extra env values
        $arrayEnv = null;
        if (is_array($envExploded)) {
            foreach ($envExploded as $variableEnv) {
                $env = explode('=', $variableEnv);
                if (isset($env[0], $env[1])) {
                    $arrayEnv[$env[0]] = $env[1];
                }
            }
        }

        $showProgress = $input->getOption('progress');
        if ($showProgress) {
            $progressBar = new ProgressBar($output);
            $maxIteration = $iterations ?: null;
            $progressBar->start((int)$maxIteration);
        }

        $command = array_merge([PHP_BINARY, self::BINARY_MAGENTO], $commandArgs);

        // Add options
        if ($output->isVerbose()) {
            $command[] = "-v";
        }
        if ($output->isVeryVerbose()) {
            $command[] = "-vv";
        }

        $i = 0;
        $failedIterationCount = 0;
        $successfulIterationCount = 0;
        while ($this->isAllowedToRun) {
            // Limit the number of iterations
            if ($iterations !== 0 && $i >= $iterations) {
                break;
            }

            // Run single thread process
            $this->currentProcess = new Process($command, BP, $arrayEnv);
            $this->currentProcess->setTimeout($timeout);

            try {
                // Run the process
                $this->currentProcess->start();

                while ($this->currentProcess->isRunning()) {
                    if ($this->pcntlEnabled) {
                        pcntl_signal_dispatch();
                    }
                    $this->flushProcessOutput($output);
                    usleep(100000);
                }
                $this->flushProcessOutput($output);

                // Check if process failed
                if (!$this->currentProcess->isSuccessful()) {
                    throw new RuntimeException($this->buildFailureMessage($this->currentProcess));
                }

                $successfulIterationCount++;
            } catch (Throwable $e) {
                // Clean up the process if it's still running
                if ($this->currentProcess->isRunning()) {
                    $this->currentProcess->stop();
                }
                $output->writeln($e->getMessage());
                $failedIterationCount++;
                if ($failOnLoop) {
                    break;
                }
            } finally {
                $this->currentProcess = null;
            }

            if ($showProgress) {
                $progressBar->advance();
            }
            $i++;

            // Delay between iterations in microseconds
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        if ($showProgress) {
            $progressBar->finish();
        }

        if ($failedIterationCount > 0) {
            $output->writeln(sprintf(
                '<comment>thread:processor completed with %d failed iteration(s) and %d successful iteration(s).</comment>',
                $failedIterationCount,
                $successfulIterationCount
            ));
        }

        if ($ignoreExitCode) {
            return Cli::RETURN_SUCCESS;
        }

        return $failedIterationCount > 0
            ? Cli::RETURN_FAILURE
            : Cli::RETURN_SUCCESS;
    }

    /**
     * Register signal handlers for graceful shutdown
     */
    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->pcntlEnabled = false;
            return;
        }

        $this->pcntlEnabled = true;
        pcntl_signal(SIGINT, [$this, 'handleSignal']);
        pcntl_signal(SIGTERM, [$this, 'handleSignal']);

        pcntl_async_signals(true);
    }

    /**
     * Handle termination signals
     *
     * @param int $signal
     */
    private function handleSignal(int $signal): void
    {
        $this->isAllowedToRun = false;

        if ($this->currentProcess && $this->currentProcess->isRunning()) {
            $this->currentProcess->stop(5);
        }
    }

    /**
     * @param string $rawCommandName
     * @param string[] $commandArgs
     * @return string[]
     */
    private function getCommandArgs(string $rawCommandName, array $commandArgs): array
    {
        $parts = preg_split('/\s+/', $rawCommandName);
        $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));
        return array_merge($parts, $commandArgs);
    }

    private function validateOptions(float $timeout, int $iterations, int $delay): void
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException('timeout must be greater than 0');
        }
        if ($iterations < 0) {
            throw new InvalidArgumentException('iterations cannot be negative');
        }
        if ($delay < 0) {
            throw new InvalidArgumentException('delay cannot be negative');
        }
    }

    private function flushProcessOutput(OutputInterface $output): void
    {
        if ($this->currentProcess === null) {
            return;
        }

        $stderr = $this->currentProcess->getIncrementalErrorOutput();
        if ($stderr !== '') {
            $output->write($stderr);
        }

        $stdout = $this->currentProcess->getIncrementalOutput();
        if ($stdout !== '') {
            $output->write($stdout);
        }
    }

    private function buildFailureMessage(Process $process): string
    {
        $parts = [
            sprintf('Wrapped command failed with exit code %d.', $process->getExitCode() ?? -1),
        ];

        $stderr = trim($process->getErrorOutput());
        $stdout = trim($process->getOutput());

        if ($stderr !== '') {
            $parts[] = $stderr;
        } elseif ($stdout !== '') {
            $parts[] = $stdout;
        }

        return implode(PHP_EOL, $parts);
    }
}
