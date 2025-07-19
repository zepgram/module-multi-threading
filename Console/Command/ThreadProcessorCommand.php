<?php
/**
 * Copyright © Username, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Console\Command;

use Exception;
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

    protected function configure(): void
    {
        $this->setDescription('Wrapper command to run a command line indefinitely in a dedicated thread');
        $this->setName(
            'thread:processor'
        )->addArgument(
            'command_name',
            InputArgument::REQUIRED,
            'The name of the command to be started.'
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

        // Argument and option
        $commandName = $input->getArgument('command_name');

        $environment = $input->getOption('environment');
        $envExploded = !empty($environment) ? explode(',', $environment) : null;
        $timeout = (float)$input->getOption('timeout') ?: 300;
        $iterations = $input->getOption('iterations') ?: 0;
        $delay = (int)$input->getOption('delay') ?: 0;

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

        // Build command
        $command = [
            PHP_BINARY,
            self::BINARY_MAGENTO,
            $commandName
        ];

        // Add options
        if ($output->isVerbose()) {
            $command[] = "-v";
        }
        if ($output->isVeryVerbose()) {
            $command[] = "-vv";
        }

        $i = 0;
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
                    pcntl_signal_dispatch();
                    usleep(100000);
                }

                // Check if process failed
                if (!$this->currentProcess->isSuccessful()) {
                    throw new RuntimeException($this->currentProcess->getErrorOutput());
                }

                // Output results
                $output->write($this->currentProcess->getErrorOutput());
                $output->write($this->currentProcess->getOutput());

            } catch (Throwable $e) {
                // Clean up the process if it's still running
                if ($this->currentProcess->isRunning()) {
                    $this->currentProcess->stop();
                }
                $output->write($e->getMessage());
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

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Register signal handlers for graceful shutdown
     */
    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

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
}
