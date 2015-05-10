<?php

namespace ultimo\orm;

abstract class Model implements \ArrayAccess {

  const ONE_TO_ONE = 'one-to-one';
  const ONE_TO_MANY = 'one-to-many';
  const MANY_TO_ONE = 'many_to_one';

  /**
   * The manager of the model. Null if the model is not yet bound to a
   * mansger.
   * @var ModelManager
   */
  protected $_manager = null;
  
  /**
   * Whether the model is newly constructed, or created from a data source.
   * @var boolean
   */
  private $_isNew = true;
  
  /**
   * The primary key value, a hashtable with the primary key fields as key and
   * their values as value. This is the value of the primary key, as it was
   * fetched from the data source. So if the primary key value is changed, then
   * this field will hold the original value.
   * @var array
   */
  public $_pkValue = array();
  
  /**
   * The 'secondary' key value, a hashtable with the primary key fields as key 
   * and their values as value. The secondary key are non-primary key fields
   * with which the model was fetched from the data source. I.e. if a message
   * model with id=6 (primary key) was fetched with user_id=2 (secondary key),
   * and this model is altered and saved, then it's only saved if the message
   * with id=6 has user_id=2.
   * This is the value of the primary key, as it was fetched from the data
   * source. So if the primary key value is changed, then this field will hold
   * the original value.
   * @var array
   */
  public $_skValue = array();
  
  /**
   * Holds a snapshot of the values at the last invocation to markAsSaved().
   * @var array
   */
  protected $_oldFieldValues = array();
  
  /**
   * The fieldnames of the model. A model should override this field.
   * @var array
   */
  static protected $fields = null;
  
  /**
   * The primary key fieldnames of the model. A model should override this
   * field.
   * @var array
   */
  static protected $primaryKey = null;
  
  /**
   * The fieldname that has the auto increment attribute. A model should
   * override this field.
   * @var string
   */
  static protected $autoIncrementField = null;
  
  /**
   * The relations of the model. A hashtable with the relationname as key and
   * an array with the relation definition as value. A relation definition is
   * an array with the name of the related model at index 0, an hashtable that
   * maps the relation fields at index 1, and the type of relation at index 2.
   * Example:
   * array('comments' => array(
   *    'MessageComment',
   *    array('submit_id' => 'commente_id', 'locale' => 'locale'),
   *    self::ONE_TO_MANY)
   * );
   * @var array
   */
  static protected $relations = array();
  
  /**
   * The scopes of the model. An array with names of static functions that
   * return a scope.
   * @var array 
   */
  static protected $scopes = array();
  
  /**
   * The fetchers of the model. An array with names of static functions that
   * return query results.
   * @var array
   */
  static protected $fetchers = array();

  /**
   * The plugins of the model. An array with classnames of plugins. If a class
   * has no namespace, the default namespace will be prepended.
   * @var array 
   */
  static protected $plugins = array();
  
  /**
   * Constructor
   */
  public function __construct() {
    $this->triggerEvent('afterConstruct');
  }
  
  /**
   * Sets the manager of the model.
   * @param ModelManager $manager The new manager of the model.
   * @return Model This instance for model design.
   */
  public function setManager(ModelManager $manager=null) {
    $this->_manager = $manager;
    return $this;
  }
  
  /**
   * Returns the manager of the model.
   * @return Manager The manager of the model.
   */
  public function getManager() {
    return $this->_manager;
  }
  
  /**
   * Returns the fieldnames of the model.
   * @return array The fieldnames of the model.
   */
  static public function getFields() {
    if (static::$fields === null) {
      throw new exceptions\ModelException('The model \'' . get_called_class() . '\' has no fields defined.', exceptions\ModelException::NO_FIELDS);
    }
    
    $fields = static::$fields;
    
    foreach (static::getPlugins() as $plugin) {
      $fields = array_merge($fields, call_user_func($plugin . '::getFields'));
    }
    
    return $fields;
  }
  
  /**
   * Returns the fieldnames of the primary key of the model.
   * @return aray The fieldnames of the primary key of the model.
   */
  static public function getPrimaryKey() {
    if (static::$primaryKey === null) {
      throw new exceptions\ModelException('The model \'' . get_called_class() . '\' has no primary key defined.', exceptions\ModelException::NO_PRIMARY_KEY);
    }
    return static::$primaryKey;
  }
  
  /**
   * Returns the fieldname that has the auto increment attribute.
   * @return string The fieldname that has the auto increment attibute, or null
   * if no field has the auto increment attribute.
   */
  static public function getAutoIncrementField() {
    return static::$autoIncrementField;
  }
  
  /**
   * Returns the relations of the model.
   * @return array The relations of the model.
   */
  static public function getRelations() {
    return static::$relations;
  }
  
  /**
   * Returns the scopes of the model.
   * @return array The scopes of the model, an array with names of static
   * functions that return query results.
   */
  static public function getScopes() {
    $scopes = array_merge(static::$scopes, array('byId', 'orderById'));
    
    foreach (static::getPlugins() as $plugin) {
      $scopes = array_merge($scopes, call_user_func($plugin . '::getScopes'));
    }
    
    return $scopes;
  }
  
  /**
   * Returns the fetchers of the model.
   * @return array The fetchers of the model, an array with names of static
   * functions that return query results.
   */
  static public function getFetchers() {
    $fetchers = array_merge(static::$fetchers, array('first', 'all', 'getById'));
    
    foreach (static::getPlugins() as $plugin) {
      $fetchers = array_merge($fetchers, call_user_func($plugin . '::getFetchers'));
    }
    
    return $fetchers;
  }
  
  /**
   * Returns the plugins of the model.
   * @return array The plugins of the model, an array with classnames of
   * plugins. If a class has no namespace, the default namespace will be
   * prepended.
   */
  static protected function getPlugins() {
    $plugins = array();
    foreach (static::$plugins as $plugin) {
      if (strpos($plugin, '\\') === false) {
        $plugin = "ultimo\orm\plugins\model\\{$plugin}";
      }
      $plugins[] = $plugin;
    }
    return $plugins;
  }
  
  /**
   * Returns the structure of the model: an hashtable field the model
   * fieldnames at key 'fields', the primary key fieldnames at key 'primaryKey',
   * the fieldname that has the auto increment attribyte at key
   * 'autoIncrementField' and the model relations at key 'relations'.
   * @return array The structure of the model.
   */
  static public function getStructure() {
    if (static::$fields === null) {
      throw new exceptions\ModelException('The model \'' . get_called_class() . '\' has no fields defined.', exceptions\ModelException::NO_FIELDS);
    }
    if (static::$primaryKey === null) {
      throw new exceptions\ModelException('The model \'' . get_called_class() . '\' has no primary key defined.', exceptions\ModelException::NO_PRIMARY_KEY);
    }
    return array(
      'fields' => static::getFields(),
      'primaryKey' => static::$primaryKey,
      'autoIncrementField' => static::$autoIncrementField,
      'relations' => static::$relations,
      'scopes' => static::getScopes(),
      'fetchers' => static::getFetchers()
    );
  }
  
  /**
   * Returns a snapshot of the field values since the last invocation to
   * markAsSaved().
   * @return array A snapshot of the field values since the last invocation to
   * markAsSaved().
   */
  public function getOldValues() {
    return $this->_oldFieldValues;
  }
  
  /**
   * Returns the value of a field at the last invocation tomarkedAsSaved().
   * @param string $fieldName Name of the field.
   * @return mixed Value of the requested field at the last invocation to
   * markedAsSaved().
   */
  public function getOldValue($fieldName) {
    return $this->_oldFieldValues[$fieldName];
  }
  
  /**
   * Returns whether a field has changed since the last invocation to
   * markedAsSaved().
   * @param string $fieldName Name of the field.
   * @return bool Whether the requested field has changed since the last
   * invocation to markedAsSaved().
   */
  public function fieldChanged($fieldName) {
    return $this->$fieldName != $this->getOldValue($fieldName);
  }
  
  /**
   * Returns the model fields and values as hashtable.
   * @return array The model fields and values as hashtable.
   */
  public function toArray() {
    $values = array();
    $fields = $this::getFields();
    foreach (get_object_vars($this) as $field => $value) {
      if (in_array($field, $fields)) {
        $values[$field] = $value;
      }
    }
    return $values;
  }
  
  /**
   * Sets the model field values from a hashtable.
   * @param array $values The hashtable with field names as key and their
   * values as value.
   * @return Model This instance for fluid design.
   */
  public function fromArray(array $values) {
   foreach ($this::getFields() as $field) {
      if (array_key_exists($field, $values)) {
        $this->$field = $values[$field];
      }
    }
    return $this;
  }
  
  /**
   * Marks the model as saved.
   * @return Model This instance for fluid design.
   */
  public function markAsSaved()
  {
    $this->_isNew = false;

    foreach($this::getPrimaryKey() as $field) {
      $this->_pkValue[$field] = $this->$field;
    }

    foreach($this->_skValue as $field => $value) {
      $this->_skValue[$field] = $this->$field;
    }
    
    $this->_oldFieldValues = $this->toArray();
    
    return $this;
  }

  /**
   * Returns whether the model is newly constructed, or created from a data
   * source.
   * @return bool Whether the model is newly constructed, or created from a data
   * source.
   */
  public function isNew() {
    return $this->_isNew;
  }
  
  /**
   * 
   * @param array $models
   * @return type
   */
  static public function multiInsert(Manager $manager, array $models) {
    foreach ($models as $model) {
      $model->triggerEvent('beforeInsert');
    }
    return $manager->modelMultiInsert($models);
    // no afterInsert event, as auto increment field is not set
  }
  
  /**
   * Saves the model. If it is a new model, the object is inserted, else it is
   * updated. This is done by calling manager.
   * @return boolean Whether the save was successful.
   */
  public function save()
  {
    if ($this->_manager === null) {
      throw new exceptions\ModelException('Could not save model. There is no manager associated with this model', exceptions\ModelException::NO_MANAGER);
    }
    
    if ($this->_isNew) {
      $this->triggerEvent('beforeInsert');
      $result = $this->_manager->modelInsert($this);
      
      if ($result) {
        $this->triggerEvent('afterInsert');
      }
    } else {
      $this->triggerEvent('beforeUpdate');
      $result = $this->_manager->modelUpdate($this);
      
      if ($result) {
        $this->triggerEvent('afterUpdate');
      }
    }
    
    return $result;
  }
  
  /**
   * Deletes the model. This is done by callinng the manager.
   * @return boolean Whether the deletion was successful.
   */
  public function delete()
  {
    if ($this->_manager === null) {
      throw new exceptions\ModelException('Could not delete model. There is no manager associated with this model', exceptions\ModelException::NO_MANAGER);
    }
    
    $this->triggerEvent('beforeDelete');
    $result = $this->_manager->modelDelete($this);
    
    if ($result) {
      $this->triggerEvent('afterDelete');
    }
    
    return $result;
  }
  
  /**
   * Returns a new lazy instance of the model.
   * @param array $keyValues The primary and secondary values.
   * @return Model The lazy instance of the model
   */
  static public function _getLazyInstance($keyValues)
  {
    /* @var $model Model */
    $model = new static();
    
    // unset all fields, because their values are unknown
    foreach ($model::getFields() as $field) {
      unset($model->$field);
    }

    $pkFields = $model::getPrimaryKey();
    // if the key values is not an array, assume it is the only primary key field
    if (!is_array($keyValues)) {
      $keyValues = array($pkFields[0] => $keyValues);
    }

    // make sure every primary key field exists in the key values
    foreach($pkFields as $pkField) {
      if (!array_key_exists($pkField, $keyValues)) {
        return null;
      }
    }

    // set each key value in the model and pk
    foreach($keyValues as $field => $value) {
      if (in_array($field, $pkFields)) {
        $model->_pkValue[$field] = $value;
      } else {
        $model->_skValue[$field] = $value;
      }
      $model->$field = $value;
    }

    $model->_isNew = false;

    return $model;
  }
  
  /**
   * Returns the value of a field in array style.
   * @param string $offset The name of the field to retreive.
   * @return mixed The value of the field to retreive.
   */
  public function offsetGet($offset) {
    return $this->$offset;
  }

  /**
   * Sets the value of a field in array style.
   * @param string $offset The name of the field to set.
   * @param mixed $value The value to set the field to.
   */
  public function offsetSet($offset, $value) {
    return $this->$offset = $value;
  }

  /**
   * Returns whether a field exits in this model in array style.
   * @param string $offset The name of the field to check the existence of.
   * @return boolean Whether the field exists in this model.
   */
  public function offsetExists($offset) {
    return isset($this->$offset);
  }

  /**
   * Unsets a field array style.
   * @param string $offset The name of the field to unset
   */
  public function offsetUnset($offset) {
    return $this->$offset = null;
  }
  
  /**
   * Prints the fieldnames and their values.
   */
  public function dump() {
    print_r($this->toArray());
  }
  
  /**
   * Starts a query with models as result on the models associated with the
   * manager.
   * @return \ultimo\orm\Query The query definition.
   */
  public function select() {
    return $this->_manager->select(static::getName());
  }
  
  /**
   * Returns the model with the specified short name and primary and secondary
   * keys. A lazy instance can be fetched, usually used for updating only.
   * @param Manager Manager to use to retrieve the model.
   * @param string|integer|array $keyValues The primary and secondary key value.
   * A hashtable must be used to specify multiple keys.
   * @param boolean $lazy Whether to get the model lazy, so data is only loaded
   * when a non known field is accessed.
   * @return \ultimo\orm\Model The model, or null if the data for the specified
   * key could not be loaded.
   */
  static public function get(Manager $manager, $keyValues, $lazy = false) {
    return $manager->get(static::getName(), $keyValues, $lazy);
  }
  
  /**
   * Creates and returns a new instance of the model bound to the manager.
   * @param Manager Manager to use to create the model.
   * @return \ultimo\orm\Model The created model.
   */
  static public function create(Manager $manager) {
    return $manager->create(static::getName());
  }
  
  /**
   * Returns the short name of the model
   * @return string The short name of the model.
   */
  static public function getName() {
    $nameElems = explode('\\', get_called_class());
    return $nameElems[count($nameElems)-1];
  }
  
  /**
   * Invoked if a class method does not exist in this class.
   * @param string $name Name of the method.
   * @param array $arguments Arguments for the method.
   * @return mixed Result of the first plugin that implements the requested
   * method.
   */
  public function __call($name, $arguments) {
    // find the first plugin that implements the requested method.
    foreach (static::getPlugins() as $pluginName) {
      if (method_exists($pluginName, $name)) {
        // create an instance of the plugin for the model. This way, plugins
        // are only constructed if needed (lazy).
        $plugin = new $pluginName($this);
        
        // call the method on the constructed plugin and return the result
        return call_user_func_array(array($plugin, $name), $arguments);
      }
    }
    
    trigger_error("No function {$name} exists in the model or plugins.", E_USER_WARNING);
  }
  
  /**
   * Invoked if a static method does not exist in this class.
   * @param string $name Name of the static method.
   * @param array $arguments Arguments for the static method.
   * @return mixed Result of the first plugin that implements the requested
   * static method.
   */
  static public function __callStatic($name, $arguments) {
    // find the first plugin that implements the requested static method.
    foreach (static::getPlugins() as $plugin) {
      if (method_exists($plugin, $name)) {
        // call the static method on the plugin and return the result
        return call_user_func_array($plugin . '::' . $name, $arguments);
      }
    }
    
    trigger_error("No static function {$name} exists in the model or plugins.", E_USER_WARNING);
  }
  
  /**
   * Returns a new StaticModel instance for this model.
   * @return \ultimo\orm\StaticModel A new StaticModel instance for this model.
   */
  public function staticModel() {
    return new StaticModel(static::getName(), $this->_manager);
  }
  
  /**
   * Fetcher for all results.
   * @param \ultimo\orm\StaticModel $staticModel StaticModel the fetcher acts
   * on.
   * @param bool $assoc Whether to return the results as associative array.
   * @return array Result of the query.
   */
  static public function all(StaticModel $staticModel, $assoc=false) {
    return $staticModel->query()->all(array(), $assoc);
  }
  
  /**
   * Fetcher for the first result.
   * @param \ultimo\orm\StaticModel $staticModel StaticModel the fetcher acts
   * on.
   * @param bool $assoc Whether to return the result as an associative array.
   * @return array|Model Result of the query.
   */
  static public function first(StaticModel $staticModel, $assoc=false) {
    return $staticModel->query()->first(array(), $assoc);
  }
  
  /**
   * Fetcher for the record with a specific id.
   * @param \ultimo\orm\StaticModel $staticModel StaticModel the fetcher acts
   * on.
   * @param bool $assoc Whether to return the result as an associative array.
   * @return array|Model Result of the query.
   */
  static public function getById(StaticModel $staticModel, $id, $assoc=false) {
    return $staticModel->query()->where('@id = ?', array($id))->first(array(), $assoc);
  }
  
  /**
   * Selector by id.
   * @param int $id Id to select on.
   */
  static public function byId($id) {
    return function($q) use ($id) {
      $q->where('@id = ?', array($id));
    };
  }
  
  static public function orderById($dir='ASC') {
    return function($q) use ($dir) {
      $q->order('id', $dir);
    };
  }
  
  /**
   * Calls a method on each plugin and on the model itself.
   * @param string $eventName Name of the event method.
   */
  protected function triggerEvent($eventName) {
    foreach (static::getPlugins() as $pluginName) {
      if (method_exists($pluginName, $eventName)) {
        $plugin = new $pluginName($this);
        $plugin->$eventName();
      }
    }
    
    if (method_exists($this, $eventName)) {
      $plugin->$eventName();
    }
  }
  
  /**
   * Returns an unique identifier for the record this model is representing.
   * @return string An unique identifier for the record this model is
   * representing.
   */
  public function getUniqueIdentifier() {
    $data = array();
    $data[] = get_called_class();
    foreach (static::$primaryKey as $field) {
      $data[] = $this->$field;
    }
    return sha1(implode('$%&#^&@^$&@!!@^', $data));
  }
}