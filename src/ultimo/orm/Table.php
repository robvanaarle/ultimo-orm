<?php

namespace ultimo\orm;

class Table implements ModelManager {
  /**
   * The database connection.
   * @var \PDO
   */
  protected $connection;
  
  /**
   * The table name, preferibly with the database name, this class is an
   * interface for.
   * @var string
   */
  protected $tableIdentifier;
  
  /**
   * The classname of the model to manager.
   * @var string
   */
  protected $modelClass;
  
  /**
   * The override model manager.
   * @var ModelManager
   */
  protected $manager;
  
  const MAX_ROWCOUNT = '18446744073709551615';
  const ONDUP_IGNORE = 1;
  const ONDUP_UPDATE = 2;
  
  /**
   * Constructor.
   * @param \PDO $connection The database connection.
   * @param string $tableIdentifier The table identifier, preferibly with the
   * database name.
   * @param string $modelClass The classname of the model to manage.
   * @param ModelManager $manager The override model manager.
   */
  public function __construct(\PDO $connection, $tableIdentifier, $modelClass, ModelManager $manager=null) {
    $this->connection = $connection;
    $this->tableIdentifier = $tableIdentifier;
    $this->modelClass = $modelClass;
    if ($manager === null) {
      $manager = $this;
    }
    $this->manager = $manager;
  }
  
  /**
   * Returns the model with the specified primary key value. It's also possible
   * to specify the primary key with other fields. On save, those extra fields
   * are put into the query.
   * @param string|integer|array $keyValues The primary and secondary key value.
   * A hashtable must be used to specify multiple keys.
   * @param boolean $lazy Whether to get the model lazy, so data is only loaded
   * when a non known field is accessed.
   * @return Model The model, or null if the data for the specified key could
   * not be loaded.
   */
  public function get($keyValues, $lazy=false) {
    // create a lazy instance
    $model = call_user_func($this->modelClass . '::_getLazyInstance', $keyValues);
    
    // if that did not work, return null. The key values are probably incorrect.
    if ($model === null) {
      return null;
    }
    
    $model->setManager($this->manager);

    // Return the lazy object, if requested.
    if ($lazy) {
      return $model;
    }
    
    // try to load the data into the model.
    try {
      $this->manager->modelLoad($model);
    } catch (exceptions\ModelException $e) {
      // rethrow the exception if there was something else wrong than no data
      if ($e->getCode() !== exceptions\ModelException::DATA_UNAVAILABLE) {
        throw $e;
      }
      
      // data not found, so return null
      return null;
    }
    
    return $model;
  }
  
  /**
   * Binds the object to this table, and calls the save function on the object.
   * @param Model $model The model to save.
   * @return boolean Whether the save was successful.
   */
  public function save(Model $model) {
    $model->setManager($this->manager);
    return $model->save();
  }
  
  /**
   * Implodes field names and values to a string.
   * @param array $fvPairs The field names and values as hashtable..
   * @param string $glue The string to glue the imploded elements together.
   * @param string $nullEqualizer The equal sign to use on null values.
   */
  protected function implodeFieldValues(array $fvPairs, $glue, $nullEqualizer) {
    $fvPairsQuoted = array();
    foreach ($fvPairs as $field => $value) {
      if ($value !== null) {
        $fvPairsQuoted[] = '`'.$field.'` = '.$this->connection->quote($value);
      } else {
        $fvPairsQuoted[] = '`'.$field.'` '.$nullEqualizer.' NULL';
      }
    }
    return implode($glue, $fvPairsQuoted);
  }
  
  protected function implodeColumnList(array $columns) {
    return '`' . implode('`, `', $columns) . '`';
  }
  
  protected function implodeValueList(array $values) {
    $implodedValues = array();
    
    foreach ($values as $value) {
      if ($value !== null) {
        $implodedValues[] = $this->connection->quote($value);
      } else {
        $implodedValues[] = 'NULL';
      }
    }
    
    return implode(', ', $implodedValues);
  }
  
  /**
   * Loads the data into the model.
   * @param Model $model The model to load the data into.
   * @return Table This instance for fluid design.
   */
  public function modelLoad(Model $model) {
    $keyValues = array_merge($model->_pkValue, $model->_skValue);
    $model->setManager($this->manager);

    // create a query to load the object
    $sql = "SELECT *\n";
    $sql .= 'FROM ' . $this->getTableIdentifier() . "\n";
    $sql .= 'WHERE ' . $this->implodeFieldValues($keyValues, ' AND ', 'IS') . "\n";
    $sql .= 'LIMIT 0,1';
    
    // execute the query, and add the values to the object
    $st = $this->connection->query($sql);

    if ($st === false || $st->rowCount() == 0) {
      throw new exceptions\ModelException('Data for the model not available in database.', exceptions\ModelException::DATA_UNAVAILABLE);
    }

    $st->setFetchMode(\PDO::FETCH_INTO, $model); 
    $model = $st->fetch();
    
    if ($model !== false) {
      $model->markAsSaved();
    }
    $st->closeCursor();
    return $this;
  }

  /**
   * Deletes a model from the manager.
   * @param  Model $model The model to delete.
   * @return boolean Whether the deletion was successful.
   */
  public function modelDelete(Model $model) {
    $sql = 'DELETE FROM ' . $this->getTableIdentifier() . "\n";
    $sql .= 'WHERE ' . $this->implodeFieldValues(array_merge($model->_pkValue, $model->_skValue), ' AND ', 'IS');

    $rows = $this->connection->exec($sql);
    return ($rows > 0 && $this->connection->errorCode() == '00000');
  }

  /**
   * Inserts a model into the manager.
   * @param Model $model The model to insert.
   * @return boolean Whether the insertion was successful.
   */
  public function modelInsert(Model $model) {

    $sql = 'INSERT INTO ' . $this->getTableIdentifier() . "\n";
    
    // does not work in sqlite
    //$sql .= 'SET ' . $this->implodeFieldValues($model->toArray(), ', ', '=');
    
    $modelValues = $model->toArray();
    $sql .= '(' . $this->implodeColumnList(array_keys($modelValues)) . ')' . "\n";
    $sql .= 'VALUES (' . $this->implodeValueList($modelValues) . ')';
    
    // perform the query, and check if an error has occured
    $this->connection->exec($sql);
    
    if ($this->connection->errorCode() != '00000') {
      return false;
    }
    
    // set the ai field, if needed
    $aiField = $model::getAutoIncrementField();
    if ($aiField !== null) {
      $model->$aiField = $this->connection->lastInsertId();
    }
    
    // attach the manager, and mark the model as saved
    $model->setManager($this->manager);
    $model->markAsSaved();
    return true;
  }

  /**
   * Updates the model in the manager.
   * @param Model $model The model to update.
   * @return boolean Whether the update was successful.
   */
  public function modelUpdate(Model $model, array $excludedFields = array()) {
    $sql = 'UPDATE ' . $this->getTableIdentifier() . "\n";
    $sql .= 'SET ' . $this->implodeFieldValues($model->toArray(), ', ', '=') . "\n";
    $sql .= 'WHERE ' . $this->implodeFieldValues(array_merge($model->_pkValue, $model->_skValue), ' AND ', 'IS');
    
    // execute the update, and check if an error occured
    $rows = $this->connection->exec($sql);
    if ($rows > 0 && $this->connection->errorCode() != '00000') {
      return false;
    }
    
    // attach the manager, and mark the model as saved
    $model->setManager($this->manager);
    $model->markAsSaved();
    return true;
  }
  
  /**
   * Returns the tableidentified this class is an interface for.
   * @return string The tableidentified this class is an interface for.
   */
  public function getTableIdentifier() {
    return $this->tableIdentifier;
  }
  
}