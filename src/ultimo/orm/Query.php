<?php
/**
 * 
 * Todo's
 * - refactor
 * - comments
 * - delete + with to same table check?
 * - think of a good, unobtrusive relStart
 *   good: #, *, &, !, ~, @
 *   bad: %, $, :, ', ", ., ?
 * 
 * Example
 * 
 * $query->select('test\Message')
 *     ->alias('COUNT(@submit.messages)', '@message_count')
 *     ->alias('MONTH(@datetime)', '@month')
 *     ->with('@submit')
 *     ->with('@submit.messages')
 *     ->where('@locale = :locale')
 *     ->where('@submit.id = :id')
 *     ->order('@locale')
 *     ->groupBy('@id')
 *     ->having('@message_count > 1')
 *     ->all(array(':locale' => 'nl_NL', ':id' => 6);
 *   
 * Notes:
 * - Currently it is impossible in mysql to multi delete with a join to a table
 * already referenced in the query. An alias is needed then, but not supported
 * by multi delete.
 *   
 */
namespace ultimo\orm;

class Query {
  /**
   * The database connection.
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
  protected $modelInfo;
  
  /**
   * The type of result of the query, TYPE_ASSOC or TYPE_MODEL.
   * This is here for BWC purposes.
   * @var integer
   */
  protected $resultType;
  
  /**
   * The manager to attach to fetched model.
   * @var Manager
   */
  protected $manager;
  
  /**
   * The name of the primary model to select in the query.
   * @var string
   */
  protected $model;
  
  /**
   * Aliases (Relation path fields) for expressions, a hashtable with aliases as
   * keys and expressions as value.
   * @var unknown_type
   */
  protected $aliases = array();
  
  /**
   * The where clause of the query.
   * @var string
   */
  protected $where;
  
  /**
   * The having clause of the query.
   * @var string
   */
  protected $having;
  
  /**
   * The group by fields of the query.
   * @var array
   */
  protected $groupBys;
  
  /**
   * All with clauses. A hashtable with relationPath as key and a where clause
   * for the left join as value.
   * @var array
   */
  protected $withs;
  
  /**
   * All set clauses as. A hashtable with fieldname as key and their value to
   * set as value.
   * @var array
   */
  protected $sets;
  
  /**
   * The order clause of the query. An array of strings consisting of the 
   * fieldname and order direction.
   * @var array
   */
  protected $orders;
  
  /**
   * The limit clause of the query.
   * @var string
   */
  protected $limit;
  
  /**
   * Relations and their corresponding structure. An hashtable with the relation
   * path as key and the structure of the model of the relation as value.
   * @var array
   */
  protected $relationStructures;
  
  /**
   * The relation path start character.
   * @var string
   */
  protected $relStart = '@';
  
  /**
   * The key of the result to store the calculated found row count into. Null to
   * not to calculate and set this value. True to calculate this value, but not
   * to set it.
   * @var string|boolean
   */
  protected $calcFoundRowsKey = null;
  
  /**
   * Parameters to replace placeholders in the query with.
   * @var array
   */
  protected $params = array(
    'where' => array(),
    'having' => array(),
    'with' => array(),
    'set' => array()
  );
  
  /**
   * The maximum number of rows to fetch in a mysql limit.
   */
  const MAX_ROWCOUNT = '18446744073709551615';
  
  const TYPE_ASSOC = 1;
  const TYPE_MODEL = 2;
  
  const MODE_SELECT = 1;
  const MODE_DELETE = 2;
  const MODE_COUNT = 3;
  const MODE_UPDATE = 4;
  
  /**
   * Constructor.
   * @param \PDO $connection The database connection.
   * @param array $modelInfo The models that can be queried.
   * @param integer $resultType The type of the result of the query.
   * @param Manager $manager The manager to attach to fetched models.
   */
  public function __construct(\PDO $connection, array $modelInfo, $resultType, ModelManager $manager=null) {
    $this->connection = $connection;
    $this->modelInfo = $modelInfo;
    $this->resultType = $resultType;
    $this->manager = $manager;
    $this->model = null;
    $this->where = '';
    $this->having = '';
    $this->groupBys = array();
    $this->limit = '';
    $this->withs = array();
    $this->relationStructures;
  }
  
  /**
   * Sets the name of the primary model to select in the query
   * @param string $model The name of the primary model to select in the query.
   * @return Query This instance for fluid design.
   */
  public function select($model) {
    if ($this->model !== null) {
      throw new exceptions\QueryException('Model already set', exceptions\QueryException::SELECT_UNAVAILABLE);
    }
    
    $this->model = $model;
    
    // the empty relation path points to the primary model to select in the 
    // query.
    $this->relationStructures[''] = $this->getStructure($model);
    return $this;
  }
  
  /**
   * Sets the key of the result to store the calculated found row count into. 
   * Null to not to calculate and set this value. True to calculate this value
   * but not to set it.
   * @var string The key to store the calculated found row count into.
   * return Query This instance for fluid design.
   */
  public function calcFoundRows($key='found_rows') {
    $key = ltrim($key, $this->relStart);
    $this->calcFoundRowsKey = $key;
    return $this;
  }
  
  /**
   * Adds an expression as an alias to the result.
   * @param string $expression Expression to add as alias.
   * @param string $aliasPath Relation path to the field used as alias. 
   * @return Query This instance for fluid design.
   */
  public function alias($expression, $aliasPath) {
    list($localPath, $aliasName) = $this->splitRelationPath(ltrim($aliasPath, $this->relStart));
    
    if (!isset($this->relationStructures[$localPath])) {
      throw new exceptions\QueryException('Could not resolve relation path: \'' . $aliasPath . '\'', exceptions\QueryException::RELATION_UNRESOLVABLE);
    }
    
    $this->aliases[$aliasPath] = $expression;
    return $this;
  }
  
  /**
   * Selects which relations to fetch with the query.
   * @param string $relationPath The path of the relation, relative to the 
   * model to select.
   * @param string $where The where-clause to filter the relation on.
   * @param boolean $fetch Whether to include the data into the result.
   * @param array $parms Parameters to add to the query.
   * @return Query This instance for fluid design.
   */
  public function with($relationPath, $where='', $fetch=true, array $params = array()) {
    $relationPath = ltrim($relationPath, $this->relStart);
    
    list($localPath, $relationName) = $this->splitRelationPath($relationPath);
    
    if (!isset($this->relationStructures[$localPath])) {
      throw new exceptions\QueryException('Could not resolve relation path: \'' . $relationPath . '\'', exceptions\QueryException::RELATION_UNRESOLVABLE);
    }
    
    if (!isset($this->relationStructures[$localPath]['relations'][$relationName])) {
      throw new exceptions\QueryException('Relation \'' . $relationName . '\' is invalid in \'' . $relationPath . '\'', exceptions\QueryException::RELATION_INVALID);
    }
    
    $this->withs[$relationPath] = array('where' => $where, 'fetch' => $fetch);
    $this->relationStructures[$relationPath] = $this->getStructure($this->relationStructures[$localPath]['relations'][$relationName][0]);
    $this->params['with'] = array_merge($this->params['with'], $params);
    return $this;
  }
  
  /**
   * Adds a where clause to the query.
   * @param string $where The where clause.
   * @param array $parms Parameters to add to the query.
   * @return Query This instance for fluid design.
   */
  public function where($where, array $params = array()) {
    if ($this->where != '') {
      $this->where .= "\n AND (" . $where . ')';
    } else {
      $this->where = '(' . $where . ')';
    }
    $this->params['where'] = array_merge($this->params['where'], $params);
    return $this;
  }
  
  /**
   * Adds a having clause to the query.
   * @param string $having The having clause.
   * @param array $parms Parameters to add to the query.
   * @return Query This instance for fluid design.
   */
  public function having($having, array $params = array()) {
    if ($this->having != '') {
      $this->having .= "\n AND (" . $having . ')';
    } else {
      $this->having = '(' . $having . ')';
    }
    $this->params['having'] = array_merge($this->params['having'], $params);
    return $this;
  }
  
  /**
   * Adds a field path to group by.
   * @param string $fieldPath Relation path to the field to group on.
   * @return Query This instance for fluid design.
   */
  public function groupBy($fieldPath) {
    list($localPath, $fieldName) = $this->splitRelationPath(ltrim($fieldPath, $this->relStart));
    
    if (!isset($this->relationStructures[$localPath])) {
      throw new exceptions\QueryException('Could not resolve relation path: \'' . $fieldPath . '\'', exceptions\QueryException::RELATION_UNRESOLVABLE);
    }
    
    if (!in_array($fieldName, $this->relationStructures[$localPath]['fields']) &&
        !isset($this->aliases[$fieldPath])) {
        throw new exceptions\QueryException('Field \'' . $fieldName . '\' is invalid in \'' . $localPath . '\'', exceptions\QueryException::FIELD_INVALID);
    }
    
    $this->groupBys[] = $fieldPath;
    return $this;
  }
  
  /**
   * Adds a set clause to the query.
   * @param string $set The set clause.
   * @param array $parms Parameters to add to the query.
   * @return Query This instance for fluid design.
   */
  public function set($set, array $params = array()) {
    $this->sets[] = $set;
    $this->params['set'] = array_merge($this->params['set'], $params);
    return $this;
  }
  
  /**
   * Adds an element to the order clause of this query.
   * @param string $fieldPath The path to the field to order on.
   * @param string $direction The director to order on (ASC/DESC).
   * @return Query This instance for fluid design.
   */
  public function order($fieldPath, $direction='ASC') {
    list($localPath, $fieldName) = $this->splitRelationPath(ltrim($fieldPath, $this->relStart));
    
    if (!isset($this->relationStructures[$localPath])) {
      throw new exceptions\QueryException('Could not resolve relation path: \'' . $fieldPath . '\'', exceptions\QueryException::RELATION_UNRESOLVABLE);
    }
    
    if (!in_array($fieldName, $this->relationStructures[$localPath]['fields']) &&
        !isset($this->aliases[$fieldPath])) {
        throw new exceptions\QueryException('Field \'' . $fieldName . '\' is invalid in \'' . $localPath . '\'', exceptions\QueryException::FIELD_INVALID);
    }
    if (strtolower($direction) == 'desc') {
      $direction = 'DESC';
    } else {
      $direction = 'ASC';
    }
    $this->orders[] = $fieldPath . ' ' . $direction;
    return $this;
  }
  
  /**
   * Applies a scope to this query.
   * @param callable $scope Scope to apply to this query.
   * @return \ultimo\orm\Query This instance for fluid design.
   */
  public function scope($scope) {
    $scope($this);
    return $this;
  }
  
  /**
   * Sets the limit-clause for this query.
   * @param integer $offset The number of records to skip when selecting.
   * @param integer $count The number of records to return, -1 to return all 
   * rows.
   * @return Query This instance for fluid design.
   */
  public function limit($offset=0, $count=-1) {
    if ($count == -1 && $offset == 0) {
      $this->limit = '';
    } else {
      if ($count == -1) {
        $count = self::MAX_ROWCOUNT;
      }
      $this->limit = intval($offset) . ', ' . intval($count);
    }
    return $this;
  }
 
  /**
   * Adds parameters to the query.
   * @param array $params Parameters to add.
   */
  protected function addParams(array $params) {
    $this->params = array_merge($this->params, $params);
  }
  
  protected function replaceRelationVars($str) {
    // regexp for replacing relations variables that can contain field aliases
    return preg_replace('/@([A-z\.]+)/', '`${1}`', $str);
  }
  
  /**
   * Returns the MySql query as string. 
   * @return string The MySql query as string.
   */
  public function toString($mode) {
    // in WHERE clauses field aliases cannot be used
    // in HAVING and ORDER BY field aliases can be used
    
    $masterAlias = '_master_';
    
    // initialize the array which holds all select-clause fields
    $fields = array();
    if ($mode == self::MODE_SELECT) {
      $fields[] = '`' . $masterAlias . '`.*';
    } elseif ($mode == self::MODE_UPDATE) {
      $fields[] = '`' . $masterAlias . '`';
    } elseif ($mode == self::MODE_DELETE) {
      // multi delete cannot use aliases
      $fields[] = $this->getTableIdentifier($this->model);
    }
    
    foreach ($this->aliases as $alias => $expression) {
      $fields[] = $expression . ' AS ' . $this->replaceRelationVars($alias);
    }
    
    // build the from-clause
    if ($mode == self::MODE_DELETE) {
      // multi delete cannot use aliases
      $from = $this->getTableIdentifier($this->model);
    } else {
      $from = $this->getTableIdentifier($this->model) . ' AS `' . $masterAlias . '`';
    }
    
    // initialize the array which holds all joins
    $joins = array();
    
    // add each relation as a join to the joins array
    foreach ($this->withs as $foreignPath => $with) {
      // retreive the needed paths and model structures
      list($localPath, $relationName) = $this->splitRelationPath($foreignPath);
      $foreignStructure = $this->relationStructures[$foreignPath];
      $localStructure = $this->relationStructures[$localPath];
      
      $relation = $localStructure['relations'][$relationName];
      
      // add child to select with path names
      if ($with['fetch']) {
        if ($mode == self::MODE_SELECT) {
          foreach ($foreignStructure['fields'] as $field) {
            $fields[] = '`' . $foreignPath . '`.`' . $field . '` AS `' . $foreignPath . '.' . $field . '`';
          }
        } elseif ($mode == self::MODE_UPDATE) {
          $fields[] = '`' . $foreignPath . '`';
        } elseif ($mode == self::MODE_DELETE) {
          $fields[] = $this->getTableIdentifier($relation[0]);
        }
      }
      
      // add left join
      $ons = array();
      foreach ($relation[1] as $localField => $foreignField) {
        //$ons[] = '`' . $localPath . '`.`' . $localField . '` = `' . $foreignPath . '`.`' . $foreignField . '`';
        if ($localPath == '') {
          $ons[] = $this->relStart . $localField . ' = ' . $this->relStart . $foreignPath . '.' . $foreignField;
        } else {
          $ons[] = $this->relStart . $localPath . '.' . $localField . ' = ' . $this->relStart . $foreignPath . '.' . $foreignField;
        }
      }
      // add a custom where to the on-clause of the join
      if ($with['where'] != '') {
       $ons[] = $with['where'];
      }
      
      // multi delete cannot use aliasses
      if ($mode == self::MODE_DELETE) {
        $joins[] = 'LEFT JOIN ' . $this->getTableIdentifier($relation[0]) . ' ON ' . implode(' AND ', $ons);
      } else {
        $joins[] = 'LEFT JOIN ' . $this->getTableIdentifier($relation[0]) . ' AS `' . $foreignPath . '` ON ' . implode(' AND ', $ons);
      }
    }
    
    // build query
    if ($mode == self::MODE_SELECT) {
      $selectOptions = '';
      if ($this->calcFoundRowsKey !== null) {
        $selectOptions = 'SQL_CALC_FOUND_ROWS ';
      }
      $sql = 'SELECT ' . $selectOptions . implode(', ', $fields) . "\nFROM " . $from;
    } elseif ($mode == self::MODE_DELETE) {
      $sql = 'DELETE ' . implode(', ', $fields) . ' FROM ' . $from;
    } elseif ($mode == self::MODE_COUNT) {
      $sql = "SELECT COUNT(*)\nFROM " . $from;
    } elseif ($mode == self::MODE_UPDATE) {
      $sql = 'UPDATE ' . $from;
    }
    
    if (!empty($joins)) {
      $sql .= "\n" . implode("\n", $joins);
    }
    
    if ($mode == self::MODE_UPDATE) {
      $sql .= "\nSET " . implode(",", $this->sets);
    }
    
    if ($this->where != '') {
      $sql .= "\nWHERE " . $this->where;
    }
    
    if (!empty($this->groupBys)) {
      $sql .= "\nGROUP BY " . $this->replaceRelationVars(implode(', ', $this->groupBys));
    }
    
    if ($this->having != '') {
      $sql .= "\nHAVING " . $this->replaceRelationVars($this->having);
    }
    
    if (!empty($this->orders)) {
      $sql .= "\nORDER BY " . $this->replaceRelationVars(implode(', ', $this->orders));
    }
    if ($this->limit != '') {
      $sql .= "\nLIMIT " . $this->limit;
    }
    
    // replace remaining relation variables (that cannot contain field aliases)
    if ($mode != self::MODE_DELETE) {
      $sql = preg_replace('/@([A-z\.]+)\.([A-z]+)/', '`${1}`.`${2}`', $sql);
      $sql = preg_replace('/@([A-z]+)/', '`' . $masterAlias . '`.`${1}`', $sql);
    } else {
      // multi delete cannot use aliases, so replace with the table name. This is
      // slower, but deletes are less common
      $sql = preg_replace_callback('/@([A-z\.]+)/', array($this, '_callback_replaceRelationVars'), $sql);
    }
    
    return $sql;
  }

  /**
   * Callback function to replace relation vars (fields) with real field
   * identifiers using a regular expression.
   * @param array $matches Matched regular expression elements, containg the
   * relation var.
   * @return string Real field identifier. 
   */
  protected function _callback_replaceRelationVars($matches) {
    list($localPath, $fieldName) = $this->splitRelationPath($matches[1]);
    if ($localPath == '') {
      $model = $this->model;
    } else {
      list($localPath, $relationName) = $this->splitRelationPath($localPath);
      $localStructure = $this->relationStructures[$localPath];
      $relation = $localStructure['relations'][$relationName];
      $model = $relation[0];
    }
    return $this->getTableIdentifier($model) . '.`' . $fieldName . '`';
  }
  
  
  /**
   * Transforms a associative flat array result to a non-flat array of
   * hashtables or models.
   * @param array $resultRows The flat result.
   * @param boolean $assoc Whether to return an associative array.
   * @return The non-flat result.
   */
  protected function transform(array $resultRows, $assoc=false) {
    // if empty, return it
    if (empty($resultRows)) {
      return array();
    }
    
    // create initial row template to group all values by relation
    $rowElemsTpl = array();
    foreach($this->relationStructures as $relationPath => $structure) {
      $rowElemsTpl[$relationPath] = array();
    }
    
    // initialize the array to store the final result in
    $result = array();
    
    // Initialize the element space in which we store unique grouped elements.
    // A grouped element contains all values belonging to one localPath.
    $elemSpace = array();
    $nextElemId = 1;
    $hashToElemId = array(null => 0);
    
    foreach ($resultRows as $row) {

      // extract all grouped elements
      $rowElems = $rowElemsTpl;
      foreach ($row as $colName => $value) {
        list($localPath, $field) = $this->splitRelationPath($colName);
        $rowElems[$localPath][$field] = $value;
      }

      
      // Initialize the array to store the elemIds of each grouped element in
      // of this row. In this array each localPath points to an elemId
      $rowElemIds = array();
      
      // merge each grouped element with the element space
      foreach ($rowElems as $relationPath => $values) {
        
        // the values could be empty if it was a join without fetching the data
        if (empty($values)) {
          continue;
        }
        
        // fetch the unique hash of the element
        $hash = $this->getPkValueHash($values, $relationPath);
        
        // check if there is already an element in space with this hash
        if (!isset($hashToElemId[$hash])) {
          // this is a new element, add it to the element space
          
          // assign an elemId for it.
          $curElemId = $nextElemId;
          $nextElemId++;
          $hashToElemId[$hash] = $curElemId;
          
          // instantiate a model from values, if models are desired
          if (!$assoc) {
            $modelClass = $this->relationStructures[$relationPath]['class'];
            
            $model = new $modelClass();
            $model->fromArray($values);
            $model->markAsSaved();
            if ($this->manager !== null) {
              $model->setManager($this->manager);
            }
            $values = $model;
          }
          
          // add the element to the space
          $elemSpace[$curElemId] = $values;
          
          // if this is a new top level element, add it to the final result
          if ($relationPath == '') {
            $result[] = &$elemSpace[$curElemId];
          }
        } else {
          // use the previously assigned hash
          $curElemId = $hashToElemId[$hash];
        }
        
        // keep track of the elemIds for each element in this row
        $rowElemIds[$relationPath] = $curElemId;
        
        
        // assign the parent relation for this element to the element space
        if ($relationPath != '') {
          
          // fetch the parent element to create the relation with
          list($localPath, $relationName) = $this->splitRelationPath($relationPath);
          $parentElem = &$elemSpace[$rowElemIds[$localPath]];
          
          // if the parent element is not null, add the relation
          if ($parentElem !== null) {
          
            // fetch the relation of the parent element with the current element
            $relation = $this->relationStructures[$localPath]['relations'][$relationName];
            
            // check what relation to add
            if (($relation[2] == Model::MANY_TO_ONE || $relation[2] == Model::ONE_TO_ONE)) {
              // add the *-to-one relation
              if ($assoc) {
                $parentElem[$relationName] = &$elemSpace[$curElemId];
              } else {
                $parentElem->$relationName = &$elemSpace[$curElemId];
              }
            } else {
              //  add the *-to-many relation
              if ($assoc && !isset($parentElem[$relationName])) {
                $parentElem[$relationName] = array();
              } elseif (!$assoc && !isset($parentElem->$relationName)) {
                $parentElem->$relationName = array();
              }
              
              // add the element to the relation collection, if the element is not null
              // and the element is not already present in the collection
              if ($assoc) {
                $parentArray = &$parentElem[$relationName];
              } else {
                $parentArray = &$parentElem->$relationName;
              }
              
              if ($hash != null && !in_array($elemSpace[$curElemId], $parentArray)) {
                $parentArray[] = &$elemSpace[$curElemId];
              }
            }
          }
        }
      }
    }
    
    return $result;
  }
  
  /**
   * Creates a unique hash based on the primary key of an array of values
   * belonging to a relationPath.
   * @param array $values The values of the relationPath
   * @param string $relationPath The path to the relation the values belong to.
   * @return string The unique hash.
   */
  protected function getPkValueHash(array $values, $relationPath) {
    $structure = $this->relationStructures[$relationPath];
    $hash = $relationPath;
    foreach ($structure['primaryKey'] as $field) {
      if ($values[$field] === null) {
        return null;
      }
      $hash .= '@' . $values[$field];
    }
    return $hash;
  }
  
  /**
   * Splits a relation path into the name of the relation and the (local) path 
   * to the relation name. Example message.tags.author to (message.tags, author)
   * @param string $relationPath The relation path.
   * @return array An array with the local path and the relation name.
   */
  protected function splitRelationPath($relationPath) {
    $sepPos = strrpos($relationPath, '.');
    if ($sepPos === false) {
      $localPath = '';
      $relationName = $relationPath;
    } else {
      $localPath = substr($relationPath, 0, $sepPos);
      $relationName = substr($relationPath, $sepPos+strlen('.'));
    }
    
    return array($localPath, $relationName);
  }
  
  /**
   * Creates and returns models of the flat result.
   * @param array $resultRows The flat result.
   * @return array Models created from the flat result.
   */
  protected function fetchModels($resultRows) {
    $models = array();
    foreach ($resultRows as $row) {
      $model = new $this->model();
      $model->fromArray($row);
      $model->markAsSaved();
      if ($this->manager !== null) {
        $model->setManager($this->manager);
      }
      $models[] = $model;
    }
    
    return $models;
  }
  
  /**
   * Returns the number of found rows count of the last query done with the
   * corresponding attribute.
   * @return integer The number of round rows count of the last query done with
   * the corresponding attribute.
   */
  public function selectFoundRows() {
    $statement = $this->connection->query('SELECT FOUND_ROWS()');
    $foundRows = $statement->fetch();
    $statement->closeCursor();
    
    return $foundRows[0];
  }
  
  /**
   * Excecutes the query and returns result non-flat or as models.
   * @param array $params The parameters to replace in the query.
   * @param bool $assoc Whether to return the result as associative table.
   * @return array The result of the query.
   */
  protected function fetchResult(array $params, $assoc) {
    $sql = $this->toString(self::MODE_SELECT);
    //echo '<pre>' . $sql . '</pre>';
    $statement = $this->connection->prepare($sql);
    $statement->execute($params);
    $result = $statement->fetchAll(\PDO::FETCH_ASSOC);
    $statement->closeCursor();
    
    $result = $this->transform($result, $assoc);
    return $result;
  }
  
  /**
   * Executes the query and returns the result assuming it could contain
   * multiple records. If set, the calculated total row count is attached.
   * @param array $params The parameters to replace in the query.
   * @return array|Collection The resulting records non-flat or as models.
   */
  public function fetch($params = array()) {
    return $this->all($params, $this->resultType == static::TYPE_ASSOC);
  }
  
  /**
   * Returns all results of the query.
   * @param array $params The parameters to replace in the query.
   * @param bool $assoc Whether to return the results as associative array.
   * @return array|Collection The resulting records non-flat or as models.
   */
  public function all(array $params = array(), $assoc=false) {
    $result = $this->fetchResult($this->buildParams($params), $assoc);
    
    if ($this->calcFoundRowsKey && $this->calcFoundRowsKey !== true) {
      $result = new Collection($result);
      $result[$this->calcFoundRowsKey] = $this->selectFoundRows();
    }
    return $result;
  }
  
  /**
   * Executes the query and returns the first result. If set, the calculated
   * total row count is attached.
   * @param array $params The parameters to replace in the query.
   * @return array|Model The resulting record non-flat or as model.
   */
  public function fetchFirst($params = array()) {
    return $this->first($params, $this->resultType == static::TYPE_ASSOC);
  }
  
  /**
   * Returns the first results of the query.
   * @param array $params The parameters to replace in the query.
   * @param bool $assoc Whether to return the result as associative array.
   * @return array|Model The resulting record non-flat or as model.
   */
  public function first(array $params = array(), $assoc=false) {
    if (empty($this->withs)) {
      $this->limit(0, 1);
    }
    $result = $this->fetchResult($this->buildParams($params), $assoc);
    if (!empty($result)) {
      $result = $result[0];
      
      if ($this->calcFoundRowsKey && $this->calcFoundRowsKey !== true) {
        $result[$this->calcFoundRowsKey] = $this->selectFoundRows();
      }
      
      return $result;
    } else {
      return null;
    }
  }
  
  /**
   * Deletes the selected records by the queery.
   * @param array $params The parameters to replace in the query.
   * @return boolean Whether the deletion was succesful.
   */
  public function delete($params = array()) {
    $query = $this->toString(self::MODE_DELETE);
    $statement = $this->connection->prepare($query);
    return $statement->execute($this->buildParams($params));
  }
  
  /**
   * Counts and returns the selected records by the queery.
   * @param array $params The parameters to replace in the query.
   * @return integer The number of selected records.
   */
  public function count($params = array()) {
    $query = $this->toString(self::MODE_COUNT);
    $statement = $this->connection->prepare($query);
    $statement->execute($this->buildParams($params));
    $row = $statement->fetch(\PDO::FETCH_NUM);
    $statement->closeCursor();
    return $row[0];
  }
  
  /**
   * Updates all selected records by the queery.
   * @param array $params The parameters to replace in the query.
   * @return boolean Whether the update was succesful.
   */
  public function update($params = array()) {
    $query = $this->toString(self::MODE_UPDATE);
    $statement = $this->connection->prepare($query);
    
    return $statement->execute($this->buildParams($params));
  }
  
  /**
   * Builds the parameter for the query.
   * @param array $params The default (last) parameters.
   * @return array All parameters for the query.
   */
  protected function buildParams(array $params) {
    $result = array();
    $result = array_merge($result, $this->params['with']);
    $result = array_merge($result, $this->params['set']);
    $result = array_merge($result, $this->params['where']);
    $result = array_merge($result, $this->params['having']);
    $result = array_merge($result, $params);
    return $result;
  }
  
  /**
   * Returns the structure of a model by model (short) name of model classname.
   * @param string $model The (short) name of classname of the model.
   * @return array The structure of the model.
   */
  protected function getStructure($model) {
    $modelInfo = $this->getModelInfo($model);
    $structure = call_user_func($modelInfo['modelClass'] . '::getStructure');
    $structure['class'] = $modelInfo['modelClass'];
    return $structure;
  }
  
  /**
   * Returns the table identifier of a model by model (short) name of model
   * classname.
   * @param string $model The (short) name of classname of the model.
   * @return array The table identifier of the model.
   */
  protected function getTableIdentifier($model) {
    $modelInfo = $this->getModelInfo($model);
    return $modelInfo['tableIdentifier'];
  }

  /**
   * Returns the info of a model by model (short) name of model classname.
   * @param string $model The (short) name of classname of the model.
   * @return array The info of the model.
   */
  protected function getModelInfo($modelClassOrName) {
    if (!isset($this->modelInfo[$modelClassOrName])) {
      throw new exceptions\ModelException("Model '{$modelClassOrName}' is not associated.", exceptions\ManagerException::UNASSOCIATED_MODELCLASS);
    }
    return $this->modelInfo[$modelClassOrName];
  }
  
  /**
   * Returns the manager associated with the query.
   * @return Manager The manager associated with the query.
   */
  public function getManager() {
    return $this->manager;
  }
  
}