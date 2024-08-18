<?php

namespace Hilos\App;

use Hilos\App\Exception\Process\CouldNotStart;
use Hilos\App\Exception\Process\FailedToClosePipe;
use Hilos\App\Exception\Process\FailedToGetProcessStatus;
use Hilos\App\Exception\Process\FailedToReadStdOut;
use Hilos\App\Exception\Process\FailedToSetStdErr;
use Hilos\App\Exception\Process\FailedToSetNonBlocking;
use Hilos\App\Exception\Process\FailedToTerminateProcess;
use Hilos\App\Exception\Process\FailedToWriteStdIn;

class Process
{
  /** @var resource $process Ресурс дочернего процесса */
  private $process;

  /** @var array<int, resource|false> $pipes Дескрипторы для stdin, stdout и stderr */
  private array $pipes = [];

  /** @var array<string, string> $stdinDescriptor Дескриптор для stdin */
  private array $stdinDescriptor;

  /** @var array<string, string> $stdoutDescriptor Дескриптор для stdout */
  private array $stdoutDescriptor;

  /** @var array<string, string> $stderrDescriptor Дескриптор для stderr */
  private array $stderrDescriptor;

  /** @var string $unreadStdOut Содержимое stdout, которое еще не было прочитано */
  private string $unreadStdOut = '';

  /** @var string $unreadStdErr Содержимое stderr, которое еще не было прочитано */
  private string $unreadStdErr = '';

  /**
   * Конструктор класса ProcessManager.
   *
   * @param string $command Команда для выполнения (например, путь к Python скрипту).
   * @param array<int, string> $params Массив параметров, которые будут переданы в команду.
   * - Каждый элемент массива `params` будет экранирован с помощью `escapeshellarg` для защиты от инъекций командной строки.
   * - Пример: `new Process('python', ['script.py', 'param1', 'param2']);`
   *
   * @throws CouldNotStart|FailedToSetNonBlocking Если не удается открыть процесс или настроить потоки.
   */
  public function __construct(
    string $command,
    array $params = [],
    ?string $cwd = null,
    array $stdIn = ["pipe", "r"],
    array $stdOut = ["pipe", "w"],
    array $stdErr = ["pipe", "w"],
  ) {
    $this->stdinDescriptor = $stdIn;
    $this->stdoutDescriptor = $stdOut;
    $this->stderrDescriptor = $stdErr;

    $descriptorSpec = [
      0 => $this->stdinDescriptor,
      1 => $this->stdoutDescriptor,
      2 => $this->stderrDescriptor,
    ];

    $fullCommand = $command . ' ' . implode(' ', array_map('escapeshellarg', $params));

    $this->process = proc_open($fullCommand, $descriptorSpec, $this->pipes, $cwd);

    if (!is_resource($this->process)) {
      throw new CouldNotStart('Could not start the process.');
    }

    if (!stream_set_blocking($this->pipes[0], false) || !stream_set_blocking($this->pipes[1], false)) {
      throw new FailedToSetNonBlocking('Failed to set non-blocking mode on streams.');
    }
  }

  /**
   * Проверяет состояние процесса и читает новые данные из stdout и stderr.
   *
   * @throws FailedToReadStdOut|FailedToSetStdErr Если не удается получить статус процесса или прочитать данные из потоков.
   * @throws FailedToGetProcessStatus
   * @throws FailedToTerminateProcess
   * @throws FailedToClosePipe
   */
  public function tick(): void
  {
    $status = $this->getStatus();

    $stdoutContent = stream_get_contents($this->pipes[1]);
    if ($stdoutContent === false) {
      throw new FailedToReadStdOut('Failed to read from stdout.');
    }
    $this->unreadStdOut .= $stdoutContent;

    $stderrContent = stream_get_contents($this->pipes[2]);
    if ($stderrContent === false) {
      throw new FailedToSetStdErr('Failed to read from stderr.');
    }
    $this->unreadStdErr .= $stderrContent;

    if (!$status['running']) {
      $this->halt(); // Ensure the process is terminated if not running
    }
  }

  /**
   * Возвращает статус процесса.
   *
   * @return array<string, mixed> Массив со статусом процесса, включая ключи 'running', 'exitcode', и другие.
   *
   * @throws FailedToGetProcessStatus Если не удается получить статус процесса.
   */
  public function getStatus(): array
  {
    $status = proc_get_status($this->process);
    if (!isset($status['running'])) {
      throw new FailedToGetProcessStatus('Failed to get process status.');
    }
    return $status;
  }

  /**
   * Останавливает процесс безопасным способом.
   *
   * @throws FailedToTerminateProcess Если не удается завершить процесс.
   * @throws FailedToGetProcessStatus
   */
  public function stop(): void
  {
    $status = $this->getStatus();
    if ($status['running']) {
      if (!proc_terminate($this->process)) {
        throw new FailedToTerminateProcess('Failed to terminate the process.');
      }
    }
  }

  /**
   * Принудительно завершает процесс.
   *
   * @throws FailedToTerminateProcess Если не удается принудительно завершить процесс.
   * @throws FailedToClosePipe
   * @throws FailedToGetProcessStatus
   */
  public function halt(): void
  {
    $status = $this->getStatus();
    if ($status['running']) {
      if (!proc_terminate($this->process, 9)) { // Send SIGKILL
        throw new FailedToTerminateProcess('Failed to forcefully terminate the process.');
      }
    }
    $this->closePipes();
  }

  /**
   * Отправляет данные в stdin дочернего процесса.
   *
   * @param string $input Данные для отправки в stdin.
   *
   * @throws FailedToWriteStdIn Если не удается записать данные в stdin.
   */
  public function sendInput(string $input): void
  {
    if (fwrite($this->pipes[0], $input) === false) {
      throw new FailedToWriteStdIn('Failed to write to stdin.');
    }
  }

  /**
   * Возвращает содержимое stdout, которое еще не было прочитано.
   *
   * @return string Содержимое stdout.
   */
  public function getStdOut(): string
  {
    return $this->unreadStdOut;
  }

  /**
   * Возвращает содержимое stderr, которое еще не было прочитано.
   *
   * @return string Содержимое stderr.
   */
  public function getStdErr(): string
  {
    return $this->unreadStdErr;
  }

  /**
   * Деструктор класса. Обеспечивает остановку процесса и освобождение ресурсов.
   *
   * @throws FailedToTerminateProcess
   * @throws FailedToClosePipe
   * @throws FailedToGetProcessStatus
   */
  public function __destruct()
  {
    $this->halt();
  }

  /**
   * Закрывает все открытые дескрипторы потоков.
   *
   * @throws FailedToClosePipe Если не удается закрыть какой-либо из потоков.
   */
  private function closePipes(): void
  {
    foreach ($this->pipes as $pipe) {
      if (is_resource($pipe) && fclose($pipe) === false) {
        throw new FailedToClosePipe('Failed to close pipe.');
      }
    }
  }
}
