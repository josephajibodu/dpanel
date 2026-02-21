<?php

namespace App\Actions\Database;

trait EscapesShell
{
    private function escapeForShell(string $s): string
    {
        return "'".str_replace("'", "'\\''", $s)."'";
    }
}
