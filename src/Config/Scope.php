<?php

declare(strict_types=1);

namespace Phorward\Config;

/**
 * Where a setting may be written down in a file.
 *
 * Project settings describe the project and are committed with it. User settings describe one person's setup and
 * stay out of repositories. Environment variables and flags may set either.
 */
enum Scope: string {
  case Project = 'project';
  case User = 'user';
}
