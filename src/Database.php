<?php

namespace PhpWebsite;

class Database
{

    protected static $instance;

    private static $servername = 'localhost';
    private static $dbname = 'DBNAME';
    private static $username = 'username';
    private static $password = 'password';

    private function __construct()
    {
        try {
            self::$instance = new \PDO('mysql:host=' . self::$servername . ';dbname=' . self::$dbname . ';charset=utf8', self::$username, self::$password);
        } catch (\PDOException $e) {
            echo "MySql Connection Error: " . $e->getMessage();
        }
    }

    public static function setParameters($servername, $dbname, $username, $password)
    {
        if (
            self::$instance &&
            !(self::$servername == $servername && self::dbname == $dbname && self::$username == $username && self::$password == $password)
        ) {
            self::$instance = null;
        }
        self::$servername = $servername;
        self::$dbname = $dbname;
        self::$username = $username;
        self::$password = $password;
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            new Database();
        }

        return self::$instance;
    }
}
