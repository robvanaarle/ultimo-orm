<?php

namespace ultimo\orm\plugins\model;

/**
 * Sequence
 * 
 * To have different groups of indexes, the model must have static public
 * field $_sequenceGroupFields. This must be an array of field names to group
 * the index on.
 * 
 * TODO: throw exceptions in move*() if model is lazy (maybe in class Model??)
 */
class Sequence extends \ultimo\orm\plugins\ModelPlugin {
  static protected $fields = array('index');
  
  static protected $scopes = array('atIndex', 'orderByIndex');
  
  static protected $fetchers = array('getMaxIndex', 'getFirst', 'getLast');
  
  /**
   * Moves the model up or down.
   * @param int $count The number of positions to move.
   */
  public function move($count) {
    if ($this->model->isNew()) {
      throw new \ultimo\orm\exceptions\ModelException("Impossible to move a new model");
    }
    
    if ($count > 0) {
      $this->moveDown($count);
    } elseif ($count < 0) {
      $this->moveUp(-$count);
    }
  }
  
  /**
   * Moves the model up.
   * @param int $count The number of positions to move up.
   */
  public function moveUp($count=1) {
    if ($this->model->isNew()) {
      throw new \ultimo\orm\exceptions\ModelException("Impossible to move a new model");
    }
    
    if ($this->model->index <= 0 || $count <= 0) {
      return;
    }

    $newIndex = max(0, $this->model->index - $count);
   
    // move all items between current item position and new item position 1 down
    $this->model->select()
                ->where('@index >= ?', array($newIndex))
                ->where('@index < ?', array($this->model->index))
                ->set('@index = @index + 1')
                ->scope($this->getLocalScope())
                ->update();
   
    // save new item position
    $this->model->index = $newIndex;
    $this->model->save();
  }
 
  /**
   * Moves the model down.
   * @param int $count The number of positions to move down.
   */
  public function moveDown($count=1) {
    if ($this->model->isNew()) {
      throw new \ultimo\orm\exceptions\ModelException("Impossible to move a new model");
    }
    
    $maxIndex = $this->model->staticModel()->scope($this->getLocalScope())
                                           ->getMaxIndex();
    
    if ($this->model->index >= $maxIndex || $count <= 0) {
      return;
    }

    $newIndex = min($maxIndex, $this->model->index + $count);
   
    // no change
    if ($newIndex == $this->model->index) {
      return;
    }
   
    // move all items between current item position and new item position 1 up
    $this->model->select()
                ->where('@index > ?', array($this->model->index))
                ->where('@index <= ?', array($newIndex))
                ->set('@index = @index - 1')
                ->scope($this->getLocalScope())
                ->update();
   
    // save new item position
    $this->model->index = $newIndex;
    $this->model->save();
  }
  
  /**
   * Returns the local scope for the model. This scope is used to allow
   * different subsets of indexes.
   * @param bool $old Whether to use the old model values.
   * @return callable Local scope.
   */
  protected function getLocalScope($old=false) {
    $model = $this->model;
    
    if (!isset($model::$_sequenceGroupFields)) {
      return function () { };
    }
    
    return function($q) use ($model, $old) {
      if ($old) {
        $values = $model->getOldValues();
      } else {
        $values = $model;
      }
      foreach ($model::$_sequenceGroupFields as $field) {
        $q->where("@{$field} = ?", array($values[$field]));
      }
    };
  }
  
  /**
   * Scopes
   */
  
  static public function atIndex($index) {
    return function ($q) use ($index) {
      return $q->where('@index = ?', array($index));
    };
  }
  
  static public function orderByIndex($dir = 'ASC') {
    return function ($q) use ($dir) {
      return $q->order('@index', $dir);
    };
  }
  
  /**
   * Fetchers
   */
  
  static public function getMaxIndex($s) {
    $row = $s->query()
             ->alias('MAX(@index)', '@max_index')
             ->first(array(), true);
   
    if ($row === null) {
      return -1;
    } else {
      return $row['max_index'];
    }
  }
  
  static public function getFirst($s, $assoc=false) {
    return $s->atIndex(0)
             ->first($assoc);
  }
  
  static public function getLast($s, $assoc=false) {   
    return $s->query()
             ->order('@index', 'DESC')
             ->first(array(), $assoc);
  }
  
  /**
   * Events
   */
  
  /**
   * Called before insertion, makes sure new models are appended at the end.
   */
  public function beforeInsert() {
    // new models are appended at the end
    $this->model->index = $this->model->staticModel()->scope($this->getLocalScope())->getMaxIndex() + 1;
  }
  
  public function afterDelete() {
    // move all items after current index one up
    $this->model->select()
                ->where('@index > ?', array($this->model->index))
                ->set('@index = @index - 1')
                ->scope($this->getLocalScope())
                ->update();
  }
  
  protected function sequenceGroupFieldChanged() {
    $model = $this->model;
    
    if (!isset($model::$_sequenceGroupFields)) {
      return false;
    }
    
    // find out if any sequence field changed
    foreach ($model::$_sequenceGroupFields as $field) {
      if ($model->fieldChanged($field)) {
        return true;
      }
    }
    
    return false;
  }
  
  public function beforeUpdate() {
    if (!$this->sequenceGroupFieldChanged()) {
      return;
    }
    
    // move all items after old index one up
    $this->model->select()
                ->where('@index > ?', array($this->model->index))
                ->set('@index = @index - 1')
                ->scope($this->getLocalScope(true))
                ->update();
    
    // append model to end of new index group
    $this->model->index = $this->model->staticModel()->scope($this->getLocalScope())->getMaxIndex() + 1;
  }

}