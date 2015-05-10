<?php

namespace ultimo\orm;

class Manager implements ModelManager {
  /**
   * The connection to the database.
   * @var \PDO
   */
  protected $connection;
  
  /**
   * The models that can be queried. A hashtable with modelnames and model
   * classnames as key and as value a hashtable with the following keys:
   * table: A Table class for the model
   * tableIdentifier: The table identifier for the model
   * modelName: The short name of the model.
   * modelClass: The model classname.
   * This information can thus be accessed by modelName or modelClass.
   * @var array
   */
  protected $modelInfo = array();
  
  /**
   * The registered short model names to be managed. Another resource can later
   * associate these.
   * @var array
   */
  protected $registeredModels = array();

  const ONDUP_UPDATE = Table::ONDUP_UPDATE;
  const ONDUP_IGNORE = Table::ONDUP_IGNORE;
  const MAX_ROWCOUNT = '18446744073709551615';
  
  /**
   * Constructor.
   * @param \PDO $connection The database connection.
   */
  public function __construct(\PDO $connection) {
    $this->connection = $connection;
    $this->init();
  }
  
  /**
   * Registers short model names to be manager by the manager. Another resource
   * can later associate these.
   * @param array $modelNames The short model names to be managed.
   * @return \ultimo\orm\Manager This instance for fluid design.
   */
  public function registerModelNames(array $modelNames) {
    $this->registeredModels = array_merge($this->registeredModels, $modelNames);
    return $this;
  }
  
  /**
   * Returns the registered short model names to be manager by the manager.
   * Another resource can use this to create associations.
   * @return array The registered short model names.
   */
  public function getRegisteredModelNames() {
    return $this->registeredModels;
  }
  
  /**
   * Called at the end of the constructor. A manager can initialize itself with
   * this function, like registering model names.
   */
  protected function init() { }
  
  /**
   * Associates a model with the manager.
   * @param string $tableIdentifier The table identifier for the model.
   * @param string $modelName The short name for the model.
   * @param string $modelClass The model classname.
   * @return \ultimo\orm\Manager This instance for fluid design.
   */
  public function associateModel($tableIdentifier, $modelName, $modelClass) {
    $table = new Table($this->connection, $tableIdentifier, $modelClass, $this);
    $modelInfo = array('table' => $table, 'tableIdentifier' => $tableIdentifier, 'modelName' => $modelName, 'modelClass' => $modelClass);
    
    $this->modelInfo[$modelName] = &$modelInfo;
    $this->modelInfo[$modelClass] = &$modelInfo;
    return $this;
  }

  /**
   * Inserts a model.
   * @param Model $model The model to insert.
   * @return boolen Whether the insertion was successful.
   */
  public function insert(Model $model) {
    return $this->modelInsert($model);
  }

  /**
   * Updates a model.
   * @param Model $model The model to update.
   * @return boolen Whether the update was successful.
   */
  public function update(Model $model) {
    return $this->modelUpdate($model);
  }

  /**
   * Saves a model.
   * @param Model $model The model to save.
   * @return boolen Whether the save was successful.
   */
  public function save(Model $model) {
    $model->setManager($this);
    $model->save();
  }

  /**
   * Deletes a model.
   * @param Model $model The model to delete.
   * @return boolen Whether the deletion was successful.
   */
  public function delete(Model $model) {
    return $this->modelDelete($model);
  }

  /**
   * Starts a query with models as result on the models associated with the
   * manager.
   * @param string $model The primary model name to query on.
   * @return \ultimo\orm\Query The query definition.
   */
  public function select($modelName) {
    $query = new Query($this->connection, $this->modelInfo, Query::TYPE_MODEL, $this);
    $query->select($this->getModelClass($modelName));
    return $query;
  }

  /**
   * Starts a query with non-flat array as result on the models associated
   * with the manager.
   * @param string $model The primary model name to query on.
   * @return \ultimo\orm\Query The query definition.
   */
  public function selectAssoc($modelName) {
    $query = new Query($this->connection, $this->modelInfo, Query::TYPE_ASSOC);
    $query->select($this->getModelClass($modelName));
    return $query;
  }

  /**
   * Creates and returns a new instance of the model with the specified short
   * model name. The manager of the model is this class.
   * @param string $modelName The short model name of the model to create.
   * @return \ultimo\orm\Model The created model.
   */
  public function create($modelName) {
    $modelClass = $this->getModelClass($modelName);
    $model = new $modelClass();
    $model->setManager($this);
    return $model;
  }
  
  /**
   * Returns the model with the specified short name and primary and secondary
   * keys. A lazy instance can be fetched, usually used for updating only.
   * @param string $modelName The short model name of the model to get.
   * @param string|integer|array $keyValues The primary and secondary key value.
   * A hashtable must be used to specify multiple keys.
   * @param boolean $lazy Whether to get the model lazy, so data is only loaded
   * when a non known field is accessed.
   * @return \ultimo\orm\Model The model, or null if the data for the specified
   * key could not be loaded.
   */
  public function get($modelName, $keyValues, $lazy = false) {
    return $this->getTable($this->getModelClass($modelName))->get($keyValues, $lazy);
  }

  /**
   * Returns the class name belonging to the short model name.
   * @param string $modelName The short model name.
   * @return string The class name belonging to the short model name.
   */
  public function getModelClass($modelName) {
    $modelInfo = $this->getModelInfo($modelName);
    return $modelInfo['modelClass'];
  }
  
  /**
   * Returns the structure of the model belonging to the short model name.
   * @param string $modelName The short model name.
   * @return array The structure name of model belonging to the short model
   * name.
   */
  public function getModelStructure($modelName) {
    return call_user_func($this->getModelClass($modelName) . '::getStructure');
  }
  
  /**
   * Returns the model info by short model name or classname.
   * @param string $modelClassOrName The short model name or classname.
   * @return array The model info.
   */
  protected function getModelInfo($modelClassOrName) {
    if (!isset($this->modelInfo[$modelClassOrName])) {
      throw new exceptions\ModelException("Model '{$modelClassOrName}' is not associated.", exceptions\ManagerException::UNASSOCIATED_MODELCLASS);
    }
    return $this->modelInfo[$modelClassOrName];
  }
  
  /**
   * Returns the table object by short model name or classname.
   * @param string $modelClassOrName The short model name or classname.
   * @return \ultimo\orm\Table The table for the model.
   */
  protected function getTable($modelClassOrName) {
    $modelInfo = $this->getModelInfo($modelClassOrName);
    return $modelInfo['table'];
  }
  
  /**
   * Loads the data into the model.
   * @param Model $model The model to load the data into.
   * @return array The values of the model.
   */
  public function modelLoad(Model $model) {
    return $this->getTable(get_class($model))->modelLoad($model);
  }

  /**
   * Deletes a model from the manager.
   * @param Model $model The model to delete.
   * @return boolean Whether the deletion was successful.
   */
  public function modelDelete(Model $model) {
    return $this->getTable(get_class($model))->modelDelete($model);
  }

  /**
   * Inserts a model into the manager.
   * @param Model $model The model to insert.
   * @return boolean Whether the insertion was successful.
   */
  public function modelInsert(Model $model) {
    return $this->getTable(get_class($model))->modelInsert($model);
  }
  
  /**
   * Inserts multiple models into the manager. It assumes all models are of the
   * same type.
   * @param array $models The models to insert.
   * @return boolean Whether the insertion was successful.
   */
  public function modelMultiInsert(array $models) {
    if (count($models) == 0) {
      return true;
    }
    
    return $this->getTable(get_class($models[0]))->modelMultiInsert($models);
  }

  /**
   * Updates the model in the manager.
   * @param Model $model The model to update.
   * @return boolean Whether the update was successful.
   */
  public function modelUpdate(Model $model) {
    return $this->getTable(get_class($model))->modelUpdate($model);
  }
  
  /**
   * Returns the static model for a short model name.
   * @param string $modelName The short model name.
   * @return \ultimo\orm\StaticModel The static model for the short model name.
   */
  public function getStaticModel($modelName) {
    return new StaticModel($modelName, $this);
  }
  
  /**
   * Invoked if a field does not exist in this manager. Used to return
   * StaticModels.
   * @param string $name Name of the field: short model name.
   * @return StaticModel The static model for the requested model.
   */
  public function __get($name) {
    // check if requested name is a valid model name, if so, return static model
    if (array_key_exists($name, $this->modelInfo)) {
      return $this->getStaticModel($name);
    }
    
    trigger_error("Undefined property {$name}", E_USER_WARNING);
  }
}