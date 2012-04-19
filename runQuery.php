<?php

    $ctrl = new Controller();
    $ctrl->start();

    class Controller
    {
        private $session;
        private $view;
        private $dbConn;

        public function __construct()
        {
            $this->session = new Session();
            $this->view = new View();
        }

        public function start()
        {
            $action = isset($_POST['action']) ? $_POST['action'] : false;

            if($action == 'login')
            {
                $this->dbConn = new DB();
                if($this->dbConn->connect
                
            }

            if($this->session->isActiveSession())
            {
                
            }
            else
            {
                $this->view->render('loginForm');
            }
        }

    }

    class DB
    {
        private $dbh;
        private $errorMsg = false;

        public function connect($host, $database, $username, $password)
        {
            try
            {
                $dsn = 'mysql:host='. $host .';dbname='. $database;
                $dbh = new PDO($dsn, $username, $password);
                $dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            catch
            {
                $this->error = $e->getMessage();
            }

            return $this->isErrorFree() ? true : false;
        }

        public function isErrorFree()
        {
            return $this->errorMsg == false ? true : false;
        }

        private function mysqlError($error)
        {
            $this->error = $error;
        }
    }

    class Session
    {
        public function __construct()
        {
            session_start();
        }

        public function isActiveSession()
        {

            return (isset($_SESSION['dbConnGood']) && $_SESSION['dbConnGood'] == true) ? true : false;
        }

    }

    class View 
    {
        public function render($view)
        {
            $this->header();
            $this->$view();
            $this->footer();
        }

        private function queryBox()
        {
            ?>

            <div id="queryBox">
                <form action="" method="post">
                    <textarea></textarea>
                    <input type="submit" name="submit" value="submit" />
                    <input type="hidden" name="action" value="runQuery">
                </form>
            </div> <!-- end queryBox -->

            <?php
        }

        private function loginForm()
        {
            ?>
                <form action="" method="post">
                    <div id="loginForm">
                        <dl>
                            <dt><label for="mysqlHost">MySQL Host</label></dt>
                            <dd><input type="text" name="mysqlHost" id="mysqlHost" value="" /></dd>
                            <dt><label for="mysqlDatabase">MySQL Database</label></dt>
                            <dd><input type="text" name="mysqlDatabase" id="mysqlDatabase"value="" /></dd>
                            <dt><label for="mysqlUsername">MySQL Username</label></dt>
                            <dd><input type="text" name="mysqlUsername" id="mysqlUsername" value="" /></dd>
                            <dt><label for="mysqlPassword">MySQL Password</label></dt>
                            <dd><input type="text" name="mysqlPassword" id="mysqlPassword" value="" /></dd>
                            <input type="hidden" name="action" value="login" />
                            <input type="submit" name="submit" value="submit" />
                        </dl>
                    </div> <!-- end loginForm -->
                </form>
            <?php
        }

        function header()
        {
            ?>

            <!doctype html>      
            <html lang="en">        
                <head>
                    <meta charset="utf-8">
                    <title>runQuery</title>
                    <meta name="description" content="runQuery">
                    <meta name="author" content="Simple MySQL App">
                </head>
                <body>
                    <div id="container">
            <?php 
        }

        function footer()
        {
            ?>

                    </div> <!-- end container -->
                </body>
            </html>

            <?php
        }
    }
