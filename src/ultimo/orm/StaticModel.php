<?php

namespace ultimo\orm;

class StaticModel {
  /**
   * Classname of the model
   * @var string 
   */
  protected $modelName;
  
  /**
   * Manager used for the static functions.
   * @var Manager 
   */
  protected $manager;
  
  /**
   * Stacked scopes in this StaticModel.
   * @var array
   */
  protected $scopes = array();
  
  
  /**
   * Constructor.
   * @param string $modelName Short name of the model.
   * @param Manager $manager Manager used for the static functions.
   */
  public function __construct($modelName, Manager $manager) {
    $this->modelName = $modelName;
    $this->manager = $manager;
  }
  
  /**
   * Invoked if a method does not exist. Used for scopes, fetchters and static
   * methods on the model (and its plugins).
   * @param string $name Name of the method.
   * @param array $arguments Arguments for the static method.
   * @return mixed Result of fetcher or static method or, if a scope was called,
   * this instance for fluid design.
   */
  public function __call($name, $arguments) {
    // TODO, maybe cache this, or cache it in manager?
    $modelStructure = $this->manager->getModelStructure($this->modelName);
    
    // find out is a scope is called
    if (in_array($name, $modelStructure['scopes'])) {
      // call the scope
      return $this->scope(call_user_func_array($this->manager->getModelClass($this->modelName) . '::' . $name, $arguments));
    }
    
    // find out is a fetcher is called
    if (in_array($name, $modelStructure['fetchers'])) {
      // call the fetcher with the static model as first argument
      array_unshift($arguments, $this);
      return call_user_func_array($this->manager->getModelClass($this->modelName) . '::' . $name, $arguments);
    }
    
    // call the static method with the manager as first argument
    array_unshift($arguments, $this->manager);
    return call_user_func_array($this->manager->getModelClass($this->modelName) . '::' . $name, $arguments);
  }
  
  /**
   * Appends a scope.
   * @param callable $scope Scope to append.
   * @return \ultimo\orm\StaticModel This instance for fluid design.
   */
  public function scope($scope) {
    $this->scopes[] = $scope;
    return $this;
  }
  
  /**
   * Returns the query for the model with all scopes applied.
   * @return Query The query for the model with all scopes applied.
   */
  public function query() {
    $query = $this->manager->select($this->modelName);
    
    // apply all scopes
    foreach ($this->scopes as $scope) {
      $scope($query);
    }
    
    return $query;
  }
  
  /**
   * Returns the manager associated with the StaticModel.
   * @retur Manager
   */
  public function getManager() {
    return $this->manager;
  }
  
}