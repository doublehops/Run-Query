<?php

    $ctrl = new Controller();
    $ctrl->start();

    class Controller
    {
        private $session;
        private $view;
        private $dbConn;
        private $alertMessages = array();

        public function __construct()
        {
            $this->session = new Session();
            $this->dbConn = new DB();
            $this->view = new View();
        }

        public function start()
        {
            $action = isset($_POST['action']) ? $_POST['action'] : false;

            if($this->session->isActiveSessionGood())
            {
                $this->dbConn->connect($_SESSION['mysqlHost'], $_SESSION['mysqlDatabase'], $_SESSION['mysqlUsername'], $_SESSION['mysqlPassword']);    
            }
            elseif($action == 'login')
            {
                $this->session->saveInputData($_POST);

                if($this->dbConn->connect($_POST['mysqlHost'], $_POST['mysqlDatabase'], $_POST['mysqlUsername'], $_POST['mysqlPassword']))
                {
                    $this->session->savePassword($_POST['mysqlPassword']);
                    $this->view->render('queryBox');
                }
                else
                {
                    $this->view->render('loginForm', $this->dbConn->getErrors());
                }
            }
            elseif($action == 'runQuery')
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
                $this->dbh = new PDO($dsn, $username, $password);
                $this->dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $this->dbh->query( 'show tables' );  // Basic query to force a test of the connection
            }
            catch (PDOException $e)
            {
                $this->errorMsg[] = $e->getMessage();
            }

            return $this->isErrorFree() ? true : false;
        }

        public function getErrors()
        {
            return $this->errorMsg;
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

        public function isActiveSessionGood()
        {

            return (isset($_SESSION['dbConnGood']) && $_SESSION['dbConnGood'] == true) ? true : false;
        }

        public function setActiveSessionGood()
        {
            $_SESSION['dbConnGood'] = true;
        }

        public function saveInputData($post)
        {
            $_SESSION['mysqlHost'] = $post['mysqlHost'];
            $_SESSION['mysqlDatabase'] = $post['mysqlDatabase'];
            $_SESSION['mysqlUsername'] = $post['mysqlUsername'];
        }

        public function savePassword($password)
        {
            $_SESSION['mysqlPassword'] = $password;
        }

    }

    class View 
    {
        public function render($view, $messages=false)
        {
            $this->header();
            if($messages != false) $this->printMessages($messages);
            $this->$view();
            $this->footer();
        }

        private function printMessages($messages)
        {
            ?>

                <div id="messages">
                    <?php foreach($messages as $message) : ?>
                        <p><?php echo $message ?></p>
                    <?php endforeach; ?>
                </div> <!-- end messages -->
            <?php
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
            $mysqlHost = isset($_SESSION['mysqlHost']) ? $_SESSION['mysqlHost'] : 'localhost';
            $mysqlDatabase = isset($_SESSION['mysqlDatabase']) ? $_SESSION['mysqlDatabase'] : '';
            $mysqlUsername = isset($_SESSION['mysqlUsername']) ? $_SESSION['mysqlUsername'] : '';

            ?>
                <form action="" method="post">
                    <div id="loginForm">
                        <dl>
                            <dt><label for="mysqlHost">MySQL Host</label></dt>
                            <dd><input type="text" name="mysqlHost" id="mysqlHost" value="<?php echo $mysqlHost ?>" /></dd>
                            <dt><label for="mysqlDatabase">MySQL Database</label></dt>
                            <dd><input type="text" name="mysqlDatabase" id="mysqlDatabase" value="<?php echo $mysqlDatabase?>" /></dd>
                            <dt><label for="mysqlUsername">MySQL Username</label></dt>
                            <dd><input type="text" name="mysqlUsername" id="mysqlUsername" value="<?php echo $mysqlUsername ?>" /></dd>
                            <dt><label for="mysqlPassword">MySQL Password</label></dt>
                            <dd><input type="password" name="mysqlPassword" id="mysqlPassword" /></dd>
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
