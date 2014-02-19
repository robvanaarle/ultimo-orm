<?php

namespace ultimo\orm;

interface ModelManager
{
  
  /**
   * Inserts a model into the manager.
   * @param Model $model The model to insert.
   * @return boolean Whether the insertion was successful.
   */
  public function modelInsert(Model $model);
  
  /**
   * Updates the model in the manager.
   * @param Model $model The model to update.
   * @return boolean Whether the update was successful.
   */
  public function modelUpdate(Model $model);
  
  /**
   * Deletes a model from the manager.
   * @param Model $model The model to delete.
   * @return boolean Whether the deletion was successful.
   */
  public function modelDelete(Model $model);
  
  /**
   * Loads the data into the model.
   * @param Model $model The model to load the data into.
   * @return array The values of the model.
   */
  public function modelLoad(Model $model);
  
}