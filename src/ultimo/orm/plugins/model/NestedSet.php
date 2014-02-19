<?php

namespace ultimo\orm\plugins\model;

class NestedSet extends \ultimo\orm\plugins\ModelPlugin {
  static protected $fields = array('left', 'right');
  
  static protected $scopes = array();
  
  static protected $fetchers = array('getRoot');
  
  public function getPath($assoc = false) {
    return $this->model->select()
                       ->where('@left <= ? AND @right >= ?', array($this->model->left, $this->model->right))
                       ->order('@left', 'ASC')
                       ->scope(static::getLocalScope($this->model))
                       ->all(array(), $assoc);
  }
  
  public function getParent($assoc = false) {
    return $this->model->select()
                       ->where('@left < ? AND @right > ?', array($this->model->left, $this->model->right))
                       ->order('@left', 'DESC')
                       ->scope(static::getLocalScope($this->model))
                       ->first(array(), $assoc);
  }
  
  public function getDepth() {
    $result = $this->model->select()
                          ->alias('COUNT(@left)', '@depth')
                          ->where('@left < ? AND @right > ?', array($this->model->left, $this->model->right))
                          ->scope(static::getLocalScope($this->model))
                          ->first(array(), true);
    if ($result === null) {
      return 0;
    }
    return $result['depth'];
  }
  
  public function getLeafNodes($assoc = false) {
    return $this->model->select()
                       ->where('@right = @left + 1')
                       ->order('@left', 'ASC')
                       ->scope(static::getLocalScope($this->model))
                       ->all(array(), $assoc);
  }
  
  public function isLeaf() {
    return ($this->model->left + 1) == $this->model->right;
  }
  
  public function descendantCount() {
    return ($this->model->right - $this->model->left - 1) / 2;
  }
  
  protected function increaseIndexes($amount) {
    $this->model->select()
                ->where('@left >= ? AND @right <= ?', array($this->model->left, $this->model->right))
                ->set('@left = @left + ?', array($amount))
                ->set('@right = @right + ?', array($amount))
                ->update();
  }
  
  protected function createSpaceInNestedSet() {
    $size = $this->model->right - $this->model->left + 1;
    
    $this->model->select()
                ->where('@left >= ?', array($this->model->left))
                ->set('@left = @left + ?', array($size))
                ->scope(static::getLocalScope($this->model))
                ->update();
    
    $this->model->select()
                ->where('@right >= ?', array($this->model->left))
                ->set('@right = @right + ?', array($size))
                ->scope(static::getLocalScope($this->model))
                ->update();
  }
  
  protected function removeSpaceInNestedSet() {
    $size = $this->model->right - $this->model->left + 1;
    
    $this->model->select()
                ->where('@left >= ?', array($this->model->right))
                ->set('@left = @left - ?', array($size))
                ->scope(static::getLocalScope($this->model))
                ->update();
    
    $this->model->select()
                ->where('@right >= ?', array($this->model->right))
                ->set('@right = @right - ?', array($size))
                ->scope(static::getLocalScope($this->model))
                ->update();
  }
  
  static protected function compareNestedSetGroup($node1, $node2) {
    if (!isset($node1::$_nestedSetGroupFields)) {
        return;
      }
    
    if ($node1->isNew()) {
      // copy type if node is new
      foreach ($node1::$_nestedSetGroupFields as $field) {
        $node1->$field = $node2->$field;
      }
    } else {
      // throw exception if groups don't match
      foreach ($node1::$_nestedSetGroupFields as $field) {
        if ($node1->$field != $node2->$field) {
          // could be done, if subset is set aside, space is removed form set, new group is copied to subset and subset is inserted into new set
          throw new Exception("Impossible to move a node from one group to another");
        }
      }
    }
  }
  
  
  const MAX_INDEX = 1000000;
  public function insertAt($newLeft) {
    if ($this->model->left <= $newLeft && $newLeft <= $this->model->right) {
      throw new \Exception("Impossible to move node within itself");
    }
    
    if (!$this->model->isNew()) {
      // set aside the subset
      $this->increaseIndexes(self::MAX_INDEX);
      
      // close the space created by the setting aside
      $this->removeSpaceInNestedSet();
      
      // newLeft could have moved after removing space
      if ($newLeft >= $this->model->left) {
        $newLeft -= ($this->model->right - $this->model->left + 1);
      }
      
      // save the amount to decrease to move the subset back in
      $decreaseValue = self::MAX_INDEX - ($newLeft - $this->model->left);
    }
    
    // set the new indices
    if ($this->model->isNew()) {
      $size = 1;
    } else {
      $size = $this->model->right - $this->model->left;
    }
    $oldLeft = $this->model->left;
    $this->model->left = $newLeft;
    $this->model->right = $newLeft + $size;
    
    // create space for the new position of the model
    $this->createSpaceInNestedSet();
    
    
    if (!$this->model->isNew()) {
      // move the subset back in
      $this->model->left = $oldLeft + self::MAX_INDEX;
      $this->model->right = $oldLeft + self::MAX_INDEX + $size;
      $this->increaseIndexes(-$decreaseValue);
    } else {
      // save the new model
      $this->model->save();
    }
  }
  
  public function insertAfter($node) {
    static::compareNestedSetGroup($this->model, $node);
    $this->model->insertAt($node->right + 1);
  }
  
  public function insertBefore($node) {
    static::compareNestedSetGroup($this->model, $node);
    $this->model->insertAt($node->left);
  }
  
  public function prependChild($node) {
    static::compareNestedSetGroup($node, $this->model);
    $node->insertAt($this->model->left + 1);
  }
  
  public function appendChild($node) {
    static::compareNestedSetGroup($node, $this->model);
    $node->insertAt($this->model->right);
  }
  
  public function getNestedSet() {
    $elements = $this->model->select()
                     ->where('@left >= ? AND @right <= ?', array($this->model->left, $this->model->right))
                     ->order('@left', 'ASC')
                     ->scope(static::getLocalScope($this->model))
                     ->all();
    
    return collections\NestedSetNode::constructFromElements($elements);
  }
  
  static protected function getLocalScope($model) {
    if (!isset($model::$_nestedSetGroupFields)) {
      return function () { };
    }
    
    return function($q) use ($model) {
      foreach ($model::$_nestedSetGroupFields as $field) {
        $q->where("@{$field} = ?", array($model[$field]));
      }
    };
  }
  
  /**
   * Fetchers
   */
  
  static public function getRoot($s, $assoc = false) {
    return $s->query()
             ->where('@left = ?', array(0))
             ->first(array(), $assoc);
  }
  
  /**
   * Events
   */
  
  public function afterConstruct() {
    $this->model->left = -1;
    $this->model->right = -1;
  }
  
  public function afterDelete() {
    // delete all descendants
    $this->model->select()
                ->where('@left > ? AND @right < ?', array($this->model->left, $this->model->right))
                ->scope(static::getLocalScope($this->model))
                ->delete();
    
    // fix the gap
    $this->removeSpaceInNestedSet();
  }
  
}