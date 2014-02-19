<?php

namespace ultimo\orm\plugins\model;

/**
 * TODO:
 * - throw exceptions in move*() if model is lazy (maybe in class Model??)
 * - dates are not updated if executed by a query
 */
class Timestamps extends \ultimo\orm\plugins\ModelPlugin {
  static protected $fields = array('creation_date', 'update_date');
  
  /**
   * Called before insertion, sets the creation and update dates.
   */
  public function beforeInsert() {
    if ($this->isDisabled()) {
      return;
    }
    
    $this->model->creation_date = date("Y-m-d H:i:s");
    $this->model->update_date = $this->model->creation_date;
  }
  
  /**
   * Called before update, sets the update date.
   */
  public function beforeUpdate() {
    if ($this->isDisabled()) {
      return;
    }
    
    $this->model->update_date = date("Y-m-d H:i:s");
  }
  
  /**
   * Returns whether timestamps are disabled.
   * @return bool Whether timestamps are disabled.
   */
  protected function isDisabled() {
    return isset($this->model->_plugins_timestamps_disable);
  }
  
  /**
   * Disables the timestamps.
   */
  public function disableTimestamps() {
    $this->model->_plugins_timestamps_disable = true;
  }
  
  /**
   * Enables the timestamps.
   */
  public function enableTimestamps() {
    unset($this->modlel->_plugins_timestamps_disable);
  }
}