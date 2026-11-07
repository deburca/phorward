<?php

declare(strict_types=1);

namespace Phorward\Config;

enum Type: string {
  case String = 'string';
  case Bool = 'bool';
  case Int = 'int';
  case Number = 'number';
  case StringList = 'list';
}
