<?php

namespace ultimo\orm;

class Collection implements \IteratorAggregate, \ArrayAccess, \Countable {
  
  /**
   * The data in the collection.
   * @var array
   */
  protected $data;
  
  /**
   * Constructor.
   * @param array $data The data in the collection.
   */
  public function __construct(array $data) {
    $this->data = $data;
  }
  
  /**
   * Return the iterator for iterating through the data.
   * @return \ArrayIterator The iterator for itering through the data.
   */
  public function getIterator() {
    return new \ArrayIterator($this->data);
  }
  
  /**
   * Returns whether the specified offset exists.
   * @param string|integer The offset.
   * @return boolean Whether the specified offset exists.
   */
  public function offsetExists($offset) {
    if (is_int($offset)) {
      return isset($this->data[$offset]);
    } else {
      return isset($this);
    }
  }
  
  /**
   * Returns the value at the specified offset.
   * @param string|integer The offset.
   * @return mixed The value at the specified offset.
   */
  public function offsetGet($offset) {
    if (is_int($offset)) {
      return $this->data[$offset];
    } else {
      return $this->$offset;
    }
  }
  
  /**
   * Sets the value at the specified offset.
   * @param string|integer The offset.
   * @return mixed The value at the specified offset.
   */
  public function offsetSet($offset, $value) {
    if (is_int($offset)) {
      $this->data[$offset] = $value;
    } else {
      $this->$offset = $value;
    }
  }
  
  /**
   * Unsets the specified offset.
   * @param string|integer The offset.
   */
  public function offsetUnset($offset) {
    if (is_int($offset)) {
      unset($this->data[$offset]);
    } else {
      unset($this);
    }
  }
  
  /**
   * Returns the number of elements of data.
   * @return integer the number of elements of data.
   */
  public function count() {
    return count($this->data);
  }
  
}