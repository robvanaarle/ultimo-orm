<?php

namespace ultimo\orm\exceptions;

class ModelException extends OrmException {
  const NO_STRUCTURE = 1;
  const DATA_UNAVAILABLE = 2;
  const NO_MANAGER = 3;
  const NO_FIELDS = 4;
  const NO_PRIMARY_KEY = 5;
}