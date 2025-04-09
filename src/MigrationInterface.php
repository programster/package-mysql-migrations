<?php

namespace Programster\MysqlMigrations;


interface MigrationInterface
{
    public function up(\mysqli $mysqliConn) : void;
    public function down(\mysqli $mysqliConn) : void;
}


