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
            $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : false;

            $this->view->render( 'header' );
            $this->view->render( 'menu' );

            if( $action == 'logout' )
            {
                $this->session->destroySession();
                $this->view->render( 'loginForm' );
                exit;
            }

            if( $this->session->isActiveSessionGood() )
            {
                $this->dbConn->connect( $_SESSION['mysqlHost'], $_SESSION['mysqlDatabase'], $_SESSION['mysqlUsername'], $_SESSION['mysqlPassword'] );
            }
            elseif( $action == 'login' )
            {
                $this->session->saveInputData( $_POST );

                if( $this->dbConn->connect( $_POST['mysqlHost'], $_POST['mysqlDatabase'], $_POST['mysqlUsername'], $_POST['mysqlPassword'] ) )
                {
                    $this->session->savePassword( $_POST['mysqlPassword'] );
                    $this->session->setActiveSessionGood();
                }
                else
                {
                    $this->view->render( 'loginForm', array( 'messages'=>$this->dbConn->getErrors() ) );
                    exit;
                }
            }
            else
            {
                $this->view->render( 'loginForm' );
                exit;
            }

            $this->view->render( 'queryBox' );

            if( $action == 'runQuery' )
            {
                $object = $this->dbConn->runQuery( $_REQUEST['query'] );

                $fields = isset( $object['fields'] ) ? $object['fields'] : false;

                $this->view->render( $object['queryType'], array( 'results'=>$object['results'], 'fields'=>$fields ) );
            }

            $this->view->render( 'footer' );
        }

    }

    class DB
    {
        private $dbh;
        private $errorMsg = false;

        public function connect( $host, $database, $username, $password )
        {
            try
            {
                $dsn = 'mysql:host='. $host .';dbname='. $database;
                $this->dbh = new PDO( $dsn, $username, $password );
                $this->dbh->setAttribute( PDO::ATTR_EMULATE_PREPARES, true );
                $this->dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

                $this->dbh->query( 'show tables' );  // Basic query to test connection
            }
            catch ( PDOException $e )
            {
                $this->errorMsg[] = $e->getMessage();
            }

            return $this->isErrorFree() ? true : false;
        }

        public function runQuery( $query )
        {
            $queryType = $this->queryType( $query );

            switch ( $queryType )
            {
                case 'showTables' :

                    $theQuery = $this->dbh->prepare( 'SHOW TABLES' );
                    $theQuery->execute();
                    $results = $theQuery->fetchAll( PDO::FETCH_OBJ );

                    break;

                case 'desc' :

                    $theQuery = $this->dbh->prepare( $query );
                    $theQuery->execute();
                    $results = $theQuery->fetchAll( PDO::FETCH_OBJ );

                    break;

                case 'select' :

                    $theQuery = $this->dbh->prepare( $query );
                    $theQuery->execute();
                    $results = $theQuery->fetchAll( PDO::FETCH_ASSOC );

                    if( preg_match( '/^select \*/i', $query ) == 1 )
                    {
                        $fields = $this->getFields( $query );
                    }

                    break;
            }

            $fields = isset( $fields ) ? $fields : false;

            return array( 'results'=>$results, 'queryType'=>$queryType, 'fields'=>$fields );
        }

        private function getFields( $query )
        {
            $fields = array();

            $tablename = explode( ' ', $query );
            $tablename = $tablename[3];

            $theQuery = $this->dbh->prepare( 'DESC '. $tablename );
            $theQuery->execute();
            $results = $theQuery->fetchAll( PDO::FETCH_OBJ );

            foreach( $results as $result )
            {
                $fields[] = $result->Field;
            }

            return $fields;
        }

        private function queryType( $query )
        {
            if( preg_match( '/^SELECT/i', $query ) ) return 'select';
            if( preg_match( '/^SHOW TABLES/i', $query ) ) return 'showTables';
            if( preg_match( '/^DESC/i', $query ) ) return 'desc';
        }

        public function getErrors()
        {
            return $this->errorMsg;
        }

        public function isErrorFree()
        {
            return $this->errorMsg == false ? true : false;
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
            return ( isset( $_SESSION['dbConnGood'] ) && $_SESSION['dbConnGood'] == true ) ? true : false;
        }

        public function setActiveSessionGood()
        {
            $_SESSION['dbConnGood'] = true;
        }

        public function saveInputData( $post )
        {
            $_SESSION['mysqlHost'] = $post['mysqlHost'];
            $_SESSION['mysqlDatabase'] = $post['mysqlDatabase'];
            $_SESSION['mysqlUsername'] = $post['mysqlUsername'];
        }

        public function savePassword( $password )
        {
            $_SESSION['mysqlPassword'] = $password;
        }

        public function destroySession()
        {
            session_destroy();
            setcookie( session_name(), '', time() - 42000) ;
            unset( $_SESSION['mysqlHost'] );
            unset( $_SESSION['mysqlDatabase'] );
            unset( $_SESSION['mysqlUsername'] );
            unset( $_SESSION['mysqlPassword'] );
        }
    }

    class View 
    {
        /*
         * second parameter to be an array of messages and results
         */
        public function render( $view, $parameters=array() )
        {
            $messages = isset( $parameters['messages'] ) ? $parameters['messages'] : false;

            if( $messages != false ) $this->printMessages( $messages );
            $this->$view( $parameters );
        }

        private function desc( $parameters )
        {
            $results = $parameters['results'];
            $tableName = substr( $_REQUEST['query'], strpos( $_REQUEST['query'], ' ' )+1 );
            ?>
            <p>Description for <a href="?action=runQuery&amp;query=SELECT%20*%20FROM%20<?php echo $tableName ?>"><?php echo $tableName ?></a></p>
            <table id="results"><tr><td>Field</td><td>Type</td><td>Null</td><td>Key</td><td>Default</td><td>Extra</td></tr><?php

            foreach( $results as $result )
            {
                ?>
                    <tr>
                        <td><?php echo  $result->Field ?></td>
                        <td><?php echo  $result->Type ?></td>
                        <td><?php echo  $result->Null ?></td>
                        <td><?php echo  $result->Key ?></td>
                        <td><?php echo  $result->Default ?></td>
                        <td><?php echo  $result->Extra ?></td>
                    </tr>

                <?php
            }
            ?></table><?php
        }

        private function select( $parameters )
        {
            $results = $parameters['results'];
            ?><table id="results"><?php

            if( $parameters['fields'] != false )
            {
                ?><tr><?php
                foreach( $parameters['fields'] as $field ) :
                ?><th><?php echo $field?></th><?php

                endforeach;
                ?></tr><?php
            }

            foreach( $results as $result ) :

                ?><tr><?php

                    foreach( $result as $value ) :
                    ?>
                        <td><?php echo $value ?></td>

                    <?php endforeach; ?>
                
                 </tr><?php

            endforeach;

            ?></table><?php
        }

        private function showTables( $parameters )
        {
            $results = $parameters['results'];

            ?><table id="results"><?php
            foreach( $results as $result )
            {
                ?>
                    <tr><td><a href="?action=runQuery&query=DESC%20<?php echo $result->Tables_in_cpoty ?>">D</a></td>
                    <td><?php echo $result->Tables_in_cpoty ?></td></tr>

                <?php
            }
            ?></table><?php
        }

        private function printMessages( $messages )
        {
            ?>

                <div id="messages">
                    <?php foreach( $messages as $message ) : ?>
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
                    <label for="query">Query</label>
                    <textarea name="query" id="query"></textarea>
                    <input type="submit" name="submit" value="submit" />
                    <input type="hidden" name="action" value="runQuery">
                </form>
            </div> <!-- end queryBox -->

            <?php
        }

        private function loginForm()
        {
            $mysqlHost = isset( $_SESSION['mysqlHost'] ) ? $_SESSION['mysqlHost'] : 'localhost';
            $mysqlDatabase = isset( $_SESSION['mysqlDatabase'] ) ? $_SESSION['mysqlDatabase'] : '';
            $mysqlUsername = isset( $_SESSION['mysqlUsername'] ) ? $_SESSION['mysqlUsername'] : '';

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

        function menu()
        {
            ?>
                <div id="menu">
                    <ul>
                        <li><a href="?action=runQuery&query=show%20tables">Show Tables</a></li>
                        <li><a href="?action=logout">Logout</a></li>
                    </ul>
                </div> <!-- end menu -->
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
