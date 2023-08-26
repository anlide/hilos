<?php

namespace Hilos\Database;

use Exception;

/**
 * Class EntityCollection
 * @package Hilos\Database
 */
class EntityCollection implements \IteratorAggregate, \ArrayAccess, \Countable {
  /** @var Entity[] */
  private $items;

  public function __debugInfo() {
    return $this->items;
  }

  public function __construct($class_name, $items = array()) {
    $this->class_name = $class_name;
    $this->items = $items;
  }

  /**
   * @throws Exception
   */
  public function save() {
    foreach ($this->items as $item) {
      $item->save();
    }
  }

  function offsetSet(mixed $key, mixed $value): void {
    if ($key) {
      $this->items[$key] = $value;
    } else {
      $this->items[] = $value;
    }
  }

  function offsetGet(mixed $key): mixed {
    if ( array_key_exists($key, $this->items) ) {
      return $this->items[$key];
    }
    return null;
  }

  function offsetUnset(mixed $key): void {
    if ( array_key_exists($key, $this->items) ) {
      unset($this->items[$key]);
    }
  }

  function offsetExists($offset): bool {
    return array_key_exists($offset, $this->items);
  }

  public function count(): int {
    return count($this->items);
  }

  public function getIterator(): \ArrayIterator {
    return new \ArrayIterator($this->items);
  }

  public function serialize(): ?string {
    return serialize($this->items);
  }

  public function unserialize($items) {
    $this->items = unserialize($items);
  }

  public function usort($callback) {
    usort($this->items, $callback);
  }
}