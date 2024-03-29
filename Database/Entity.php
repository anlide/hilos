<?php

namespace Hilos\Database;

use Hilos\Database\Exception\Sql;

abstract class Entity {
  private bool $_related = false;

  /*
  const _table = 'example';
  const _primary = 'id_example';
  const _columns = ['id_example', 'example'];
  const _child = [
    'example_locales' => ['id_example' => 'example_locale'],
  ];
  */

  public function __clone() {}
  public function __construct() {}

  /**
   * @param array|string $columns
   * @return bool
   * @throws Sql
   */
  public function save($columns = array()): bool {
    if (!is_array($columns)) $columns = array($columns);
    $wasRelated = $this->_related;
    if ($this->_related) {
      $this->saveUpdate($columns);
    } else {
      $this->saveInsert();
      $this->_related = true;
    }

    return $wasRelated;
  }

  /**
   * @throws Sql
   */
  private function saveInsert(): void
  {
    $class = get_called_class();
    $tmp_params_pattern = array();
    $values = array();
    foreach($class::_columns as $column) {
      $tmp_params_pattern[] = '?';
      $values[] = $class::_types[$column] !== 'timestamp' ? $this->$column : ($this->$column === null ? null : date('Y-m-d H:i:s', $this->$column));
    }
    $table = (defined($class.'::_database') ? '`'.$class::_database.'`.' : '') . '`'.$class::_table.'`';
    Database::sql('INSERT INTO '.$table.' (`'.implode('`, `', $class::_columns).'`) VALUES ('.implode(', ', $tmp_params_pattern).');', $values);
    $_primary = $class::_primary;
    if (!is_array($_primary) && $_primary !== null) {
      $last_insert_id = Database::lastInsertId();
      if ($last_insert_id != 0) {
        $this->$_primary = $last_insert_id;
      }
    } elseif (is_array($_primary)) {
      if (defined($class.'::_auto_increment')) {
        $_auto_increment = $class::_auto_increment;
        $last_insert_id = Database::lastInsertId();
        $this->$_auto_increment = $last_insert_id;
      }
    }
  }

  /**
   * @param array $columns
   * @throws Sql
   */
  private function saveUpdate(array $columns = array()): void
  {
    $class = get_called_class();
    $_primary = $class::_primary;
    $values = array();
    $updates = array();
    foreach($class::_columns as $column) {
      if (!is_array($class::_primary)) {
        if ($column == $_primary) continue;
      } else {
        $continue = false;
        foreach ($class::_primary as $value) {
          if ($column == $value) {
            $continue = true;
            break;
          }
        }
        if ($continue) continue;
      }
      if ((empty($columns)) || (in_array($column, $columns))) {
        $values[] = $class::_types[$column] !== 'timestamp' ? $this->$column : ($this->$column === null ? null : date('Y-m-d H:i:s', $this->$column));
        $updates[] = '`'.$column.'` = ?';
      }
    }
    if (empty($updates)) return;
    $table = (defined($class.'::_database') ? '`'.$class::_database.'`.' : '') . '`'.$class::_table.'`';
    if (!is_array($class::_primary)) {
      $values[] = $this->$_primary;
      Database::sql('UPDATE '.$table.' SET '.implode(', ', $updates).' WHERE `'.$_primary.'` = ?;', $values);
    } else {
      $primary_str = array();
      foreach ($class::_primary as $value) {
        $values[] = $this->$value;
        $primary_str[] =  '`'.$value.'` = ?';
      }
      Database::sql('UPDATE '.$table.' SET '.implode(', ', $updates).' WHERE '.implode(' AND ', $primary_str).';', $values);
    }
  }

  /**
   * @throws Sql
   */
  public function delete(): void
  {
    $class = get_called_class();
    $_primary = $class::_primary;
    $table = (defined($class.'::_database') ? '`'.$class::_database.'`.' : '') . '`'.$class::_table.'`';
    if (!is_array($class::_primary)) {
      Database::sql('DELETE FROM ' . $table . ' WHERE `' . $_primary . '` = ?;', $this->$_primary);
    } else {
      $primary_str = array();
      $values = [];
      foreach ($class::_primary as $value) {
        $values[] = $this->$value;
        $primary_str[] =  '`'.$value.'` = ?';
      }
      Database::sql('DELETE FROM ' . $table . ' WHERE '.implode(' AND ', $primary_str).';', $values);
    }
  }

  /**
   * @param $row
   */
  protected function setRelatedData($row): void
  {
    $class = get_called_class();
    foreach($row as $column => $value) {
      if (isset($class::_types[$column])) {
          $this->$column = match ($class::_types[$column]) {
              'integer' => ($value === null ? null : intval($value)),
              'double' => ($value === null ? null : doubleval($value)),
              'boolean' => ($value === null ? null : boolval($value)),
              'timestamp' => ($value === null ? null : strtotime($value)),
              default => $value,
          };
      } else {
        $this->$column = $value;
      }
    }
    $this->_related = true;
  }

  public function isRelated(): bool {
    return $this->_related;
  }

  public function flushRelated(): void {
    $this->_related = true;
    // TODO: Throw exception if auto_increment=true
  }

  /**
   * @param $class
   * @param array $filters
   * @param array $filters_param
   * @param array $order_by
   * @return EntityCollection
   * @throws Sql
   */
  private static function getObjects($class, array $filters = array(), array $filters_param = array(), array $order_by = array()): EntityCollection {
    $objs = new EntityCollection($class);
    $order_str = '';
    if (count($order_by) > 0) {
      $order_str = 'ORDER BY '.implode(',', $order_by);
    }
    $table = (defined($class.'::_database') ? '`'.$class::_database.'`.' : '') . '`'.$class::_table.'`';
    if (count($filters) == 0) {
      $rows = Database::rows('SELECT `'.implode('`, `', $class::_columns).'` FROM '.$table.' '.$order_str.';');
    } else {
      $rows = Database::rows('SELECT `'.implode('`, `', $class::_columns).'` FROM '.$table.' WHERE '.implode(' AND ', $filters).' '.$order_str.';', $filters_param);
    }
    foreach ($rows as $row) {
      /** @var $obj Entity */
      $obj = new $class();
      $obj->setRelatedData($row);
      $objs[] = $obj;
    }
    return $objs;
  }

  /**
   * @param array|string $filters
   * @param array|string $filters_param
   * @param array|string $order_by
   * @return EntityCollection
   * @throws Sql
   */
  public static function get(array|string $filters = [], array|string $filters_param = [], array|string $order_by = []): EntityCollection {
    if (!is_array($filters)) $filters = array($filters);
    if (!is_array($filters_param)) $filters_param = array($filters_param);
    if (!is_array($order_by)) $order_by = array($order_by);
    return self::getObjects(get_called_class(), $filters, $filters_param, $order_by);
  }

  /**
   * @throws Sql
   */
  public static function getById($id) {
    $class = get_called_class();
    if (!is_array($id)) {
      $objs = self::getObjects($class, array('`'.$class::_primary.'` = ?'), array($id));
    } else {
      $fields = $class::_primary;
      $ids = array();
      foreach ($fields as $field) {
        $ids[] = '`'.$field.'` = ?';
      }
      $objs = self::getObjects($class, $ids, $id);
    }
    if (count($objs) == 0) return null;
    return $objs[0];
  }

  public static function getEmpty() {
    $class = get_called_class();
    return new $class();
  }

  /**
   * @param $name
   * @return array|EntityCollection|mixed|null
   * @throws Sql
   */
  public function __get($name) {
    $class = get_called_class();
    $column = 'id_'.$name;
    if (defined($class.'::_foreign')) {
      if (!is_null($class::_foreign)) {
        $tmp = $class::_foreign;
        if (isset($tmp[$column])) {
          /** @var Entity $class_sub */
          $class_sub = 'hilos_obj_'.$class::_foreign[$column]; // TODO: reimplement it
          return $class_sub::getById($this->$column);
        }
      }
    }
    if (defined($class.'::_child')) {
      if (is_array($class::_child[$name])) {
        if (count($class::_child[$name]) == 1) {
          $id = key($class::_child[$name]);
          /** @var Entity $class_sub */
          $class_sub = 'hilos_obj_'.current($class::_child[$name]); // TODO: reimplement it
          return $class_sub::get('`'.$id.'` = ?', $this->$id);
        } else {
          $keys = [];
          $values = [];
          $class_sub = null;
          /** @var Entity $class_sub */
          $class_sub = 'hilos_obj_'.current($class::_child[$name]); // TODO: reimplement it
          foreach ($class::_child[$name] as $key => $value) {
            $keys[] = '`'.$key.'` = ?';
            $values[] = $this->$key;
          }
          return $class_sub::get($keys, $values);
        }
      } else {
        // TODO: implement this
      }
    }
    return null;
  }

  /**
   * @throws Sql
   */
  public static function getAll(): EntityCollection {
    return self::get();
  }
}