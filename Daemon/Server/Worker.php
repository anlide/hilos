<?php

namespace Hilos\Daemon\Server;

use Exception;
use Hilos\App\Process;
use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Client\Worker as ClientWorker;
use Hilos\Daemon\Exception\SocketAcceptUnable;
use Hilos\Daemon\Task\IMaster;
use Hilos\Daemon\Task\Master as TaskMaster;
use Hilos\Service\Config;

class Worker extends Server {

  protected $classWorkerClient;

  /** @var Process[] */
  private array $processes = [];

  /** @var ClientWorker[] */
  protected array $clients = [];

  /** @var TaskMaster[] */
  protected array $delayTasks = [];

  /** @var int */
  private int $monopoly = 0;

  function __construct($port, $host, $classWorkerClient = ClientWorker::class) {
    $this->port = $port;
    $this->host = $host;
    $this->classWorkerClient = $classWorkerClient;
    $this->autoStart = true;
  }

  /**
   * @param $initialFile
   * @param $count
   * @param int $monopoly
   * @throws Exception
   */
  public function runWorkers($initialFile, $count, int $monopoly = 0): void
  {
    $this->monopoly = $monopoly;
    for ($index = 0; $index < $count; $index++) {
      $add = '';
      if (strtolower(substr(PHP_OS, 0, 3)) != 'win') $add = 'exec ';
      $this->processes[$index] = new Process(
        $add . Config::env('PHP_RUN_DIR') . ' ' . $initialFile,
        ['index-'.$index],
        dirname($initialFile),
        ["pipe", "r"],
        ["pipe", "w"],
        ["file", Config::env('HILOS_LOG_PATH').str_replace('%0', $index, Config::env('HILOS_WORKER_LOG_ERROR_FILE')), "a"],
      );
      $this->processes[$index]->sendInput('index-'.$index.PHP_EOL);
    }
  }

  /**
   * @param IMaster $task
   * @param bool $delay
   * @return bool
   * @throws Exception
   */
  public function addTask(IMaster &$task, bool $delay = false): bool {
    $minIndex = null;
    $minCount = null;
    $defaultMonopolyIndex = null;
    foreach ($this->clients as $index => &$client) {
      if ($client->getMonopolyStatus() === false) {
        $defaultMonopolyIndex = $minIndex = $index;
      }
      $count = $client->taskCount();
      if ((($minCount === null) || ($minCount > $count)) && ($client->getMonopolyStatus() !== true)) {
        $minIndex = $index;
        $minCount = $count;
      }
      if (($task->isMonopoly()) && ($client->getMonopolyStatus() === true) && ($count === 0)) {
        $this->clients[$index]->taskAdd($task);
        $task->setClient($index);
        return true;
      }
    }
    unset($client);
    if ($task->isMonopoly()) {
      if ($defaultMonopolyIndex === null) {
        throw new Exception('Something went wrong -- should be default worker for monopoly tasks (i.e. wrong monopoly configuration - add monopoly workers is fast workaround). But here some bug.');
      }
      $this->clients[$defaultMonopolyIndex]->taskAdd($task);
      $task->setClient($defaultMonopolyIndex);
      return true;
    }
    if ($minIndex === null) {
      if (!$delay) {
        $this->delayTasks[] = $task;
      }
    } else {
      $this->clients[$minIndex]->taskAdd($task);
      $task->setClient($minIndex);
      return true;
    }
    return false;
  }

  /**
   * @throws Exception
   */
  function tick(): void
  {
    if ((count($this->delayTasks) == 0) || (count($this->clients) == 0)) return;
    foreach ($this->delayTasks as $index => $task) {
      if ($this->addTask($task, true)) {
        unset($this->delayTasks[$index]);
      }
    }
    unset($task);
    foreach ($this->processes as $process) {
      $process->tick();
    }
  }

  function stop() {
    parent::stop();
    $this->processes = [];
  }

  /**
   * @return ClientWorker
   * @throws SocketAcceptUnable
   */
  function accept(): IClient {
    if ($socket = socket_accept($this->socket)) {
      socket_set_nonblock($socket);
      $newClient = new $this->classWorkerClient($socket);
      $this->clients[] = $newClient;
      if (count($this->clients) === 1) {
        $newClient->setMonopolyStatus(false);
      } elseif ($this->monopoly > 0) {
        $newClient->setMonopolyStatus(true);
        $this->monopoly--;
      } else {
        $newClient->setMonopolyStatus(null);
      }
      return $newClient;
    } else {
      throw new SocketAcceptUnable('Worker');
    }
  }

  function getClientsCount(): int {
    return count($this->clients);
  }
}