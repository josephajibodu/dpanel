<?php

namespace App\Exceptions;

use Exception;

/**
 * A backup or restore failure whose message is written for the user and is
 * safe to show them as-is.
 */
class BackupFailedException extends Exception {}
