<?php
namespace Hilos\Daemon\Client;

interface IClient {
  function getSocket();
  function handle();
  function stop();
  function closed();
  function tick();
}