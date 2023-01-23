<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Console\Command;

use Exception;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Helper\ProgressBar;

class ThreadProcessorCommand extends Command
{
    /** @var string */
    public const BINARY_MAGENTO = 'bin/magento';

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
        // Argument and option
        $commandName = $input->getArgument('command_name');
        //$this->getApplication()->get($commandName);

        $environment = $input->getOption('environment');
        $envExploded = !empty($environment) ? explode(',', $environment) : null;
        $timeout = $input->getOption('timeout') ?: 300;
        $iterations = $input->getOption('iterations') ?: 0;

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
        while (true) {
            // Limit the number of iterations
            if ($iterations !== 0 && $i >= $iterations) {
                break;
            }

            // Run single thread process
            $process = new Process($command, BP, $arrayEnv);
            $process->setTimeout($timeout);

            // Handle interrupt signal
            pcntl_signal(SIGINT, function () use ($process) {
                $process->stop();
            });

            // Run the process and output
            $process->mustRun();
            $output->write($process->getErrorOutput());
            $output->write($process->getOutput());

            if ($showProgress) {
                $progressBar->advance();
            }
            $i++;
        }

        if ($showProgress) {
            $progressBar->finish();
        }

        return Cli::RETURN_SUCCESS;
    }
}
