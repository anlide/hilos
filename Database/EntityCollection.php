<?php

namespace Hilos\Database;

/**
 * Class EntityCollection
 * @package Hilos\Database
 */
class EntityCollection implements \IteratorAggregate, \ArrayAccess, \Countable, \Serializable {
  /** @var string */
  private $class_name;

  /** @var Entity[] */
  private $items;

  public function __debugInfo() {
    return $this->items;
  }

  public function __construct($class_name, $items = array()) {
    $this->class_name = $class_name;
    $this->items = $items;
  }

  public function save() {
    foreach ($this->items as $item) {
      $item->save();
    }
  }

  function offsetSet($key, $value) {
    if ($key) {
      $this->items[$key] = $value;
    } else {
      $this->items[] = $value;
    }
  }

  function offsetGet($key) {
    if ( array_key_exists($key, $this->items) ) {
      return $this->items[$key];
    }
    return null;
  }

  function offsetUnset($key) {
    if ( array_key_exists($key, $this->items) ) {
      unset($this->items[$key]);
    }
  }

  function offsetExists($offset) {
    return array_key_exists($offset, $this->items);
  }

  public function count() {
    return count($this->items);
  }

  public function getIterator() {
    return new \ArrayIterator($this->items);
  }

  public function serialize() {
    return serialize($this->items);
  }

  public function unserialize($items) {
    $this->items = unserialize($items);
  }

  public function usort($callback) {
    usort($this->items, $callback);
  }
}