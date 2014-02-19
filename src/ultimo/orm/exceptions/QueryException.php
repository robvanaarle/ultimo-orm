<?php

namespace ultimo\orm\exceptions;

class QueryException extends OrmException {
  const RELATION_UNRESOLVABLE = 1;
  const RELATION_INVALID = 2;
  const FIELD_INVALID = 3;
  const WITH_UNAVAILABLE = 4;
  const SELECT_UNAVAILABLE = 5;
  const CAL_FOUND_ROWS_UNAVAILABLE = 6;
}