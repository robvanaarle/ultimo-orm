<?php

namespace ultimo\orm\plugins;

abstract class ModelPlugin {
  
  /**
   * The model the plugin is for.
   * @var \ultimo\orm\Model 
   */
  protected $model;
  
  /**
   * The fieldnames of the model. A model should override this field.
   * @var array
   */
  static protected $fields = null;
  
  /**
   * The scopes of the model. An array with names of static functions that
   * return a scope.
   * @var array 
   */
  static protected $scopes = array();
  
  static protected $fetchers = array();
  
  /**
   * Constructor.
   * @param \ultimo\orm\Model $model The model the plugin is for.
   */
  public function __construct(\ultimo\orm\Model $model) {
    $this->model = $model;
  }
  
  static public function getFields() {
    return static::$fields;
  }
  
  static public function getScopes() {
    return static::$scopes;
  }
  
  static public function getFetchers() {
    return static::$fetchers;
  }
}