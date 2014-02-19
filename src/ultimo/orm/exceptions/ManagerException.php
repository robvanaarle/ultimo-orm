<?php

namespace ultimo\orm\exceptions;

class ManagerException extends OrmException {
  const UNASSOCIATED_MODELCLASS = 1;
  const UNASSOCIATED_MODELNAME = 2;
}