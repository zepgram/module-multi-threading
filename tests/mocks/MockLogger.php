<?php
declare(strict_types=1);
interface MinimalLoggerInterface {
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    public function log($level, string|\Stringable $message, array $context = []): void;
}
if (!interface_exists('Psr\Log\LoggerInterface')) {
    eval('namespace Psr\Log; interface LoggerInterface extends \MinimalLoggerInterface {}');
}
class MockLogger implements \Psr\Log\LoggerInterface {
    public array $logs = [];
    public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function log($level, string|\Stringable $message, array $context = []): void {
        $this->logs[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
    }
    public function hasMessage(string $needle, ?string $level = null): bool {
        foreach ($this->logs as $log) {
            if ($level !== null && $log['level'] !== $level) continue;
            if (strpos($log['message'], $needle) !== false) return true;
        }
        return false;
    }
}
