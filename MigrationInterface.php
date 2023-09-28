<?php

namespace iRAP\Migrations;

use mysqli;

interface MigrationInterface
{
    public function up(mysqli $mysqliConn);
    public function down(mysqli $mysqliConn);
}


