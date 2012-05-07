<?php

    $ctrl = new Controller();
    $ctrl->start();

    /*
     *  Control the flow of the application. 
     */
    class Controller
    {
        private $session;
        private $view;
        private $dbConn;
        private $alertMessages = array();

        /*
         *   Create instances of Session, DB and View
         */
        public function __construct()
        {
            $this->session = new Session();
            $this->dbConn = new DB();
            $this->view = new View();
        }

        /*
         *  Start handling requests
         */
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

            // If current session available, reconnect to DB
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

    /*
     *  class DB
     *  Handle all database connections, queries and errors.
     */
    class DB
    {
        private $debug = true;
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
                case 'insert' :

                    $numRows = $this->executeMod( $query, PDO::FETCH_ASSOC );

                    break;

                case 'update' :

                    //$results = $this->executeMod( 'SHOW TABLES', PDO::FETCH_ASSOC );

                    break;

                case 'showTables' :

                    $results = $this->executeQuery( 'SHOW TABLES', PDO::FETCH_ASSOC );

                    break;

                case 'desc' :

                    $results = $this->executeQuery( $query, PDO::FETCH_OBJ );

                    break;

                case 'select' :

                    $results = $this->executeQuery( $query, PDO::FETCH_ASSOC );

                    if( $this->isErrorFree() )
                    {
                        if( preg_match( '/^select \*/i', $query ) == 1 )
                        {
                            $fields = $this->getFields( $query );
                        }
                    }
                    else
                    {
                        die( 'handle error' );
                    }

                    break;
                
                case '' :

                    die( 'No query received' );

                    break;
            }

            $fields = isset( $fields ) ? $fields : false;

            return array( 'results'=>$results, 'queryType'=>$queryType, 'fields'=>$fields );
        }

        /*
         *  Execute query to fetch data
         */
        private function executeQuery( $query, $fetchMode )
        {
            try
            {
                $theQuery = $this->dbh->prepare( $query );
                if( $this->debug ) $this->dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
                $theQuery->execute();
                $results = $theQuery->fetchAll( $fetchMode );
            }
            catch ( PDOException $e )
            {
                $this->errorMsg[] = $e->getMessage();
                $results = false;

                // TODO: Handle expceptions
                die( var_dump( $this->errorMsg ) );
            }

            return $results;
        }

        /*
         *  Execute query to modify (insert/update/delete) row
         */
        private function executeMod( $query, $fetchMode )
        {
            try
            {
                $theQuery = $this->dbh->prepare( $query );
                if( $this->debug ) $this->dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
                $theQuery->execute();
                $numRows = $theQuery->rowCount();
            }
            catch ( PDOException $e )
            {
                $this->errorMsg[] = $e->getMessage();
                $numRows = 0;

                // TODO: Handle expceptions
                die( var_dump( $this->errorMsg ) );
            }

            return $numRows;
        }

        /*
         *  Return field (or column) names
         */
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

        /*
         *  Determine the type of query that's being requested and handle accordingly
         */
        private function queryType( $query )
        {
            if( preg_match( '/^INSERT/i', $query ) ) return 'insert';
            if( preg_match( '/^SELECT/i', $query ) ) return 'select';
            if( preg_match( '/^SHOW TABLES/i', $query ) ) return 'showTables';
            if( preg_match( '/^DESC/i', $query ) ) return 'desc';
        }

        /*
         *  Return error messages recorded
         */
        public function getErrors()
        {
            return $this->errorMsg;
        }

        /*
         *  Return whether last query encountered errors
         */
        public function isErrorFree()
        {
            return $this->errorMsg == false ? true : false;
        }
    }

    /*
     *  class Session
     *  Handle all session data to store database connection info between page loads
     */
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
            if( headers_sent() ) return;
            
            session_destroy();
            setcookie( session_name(), '', time() - 42000) ;
            unset( $_SESSION['mysqlHost'] );
            unset( $_SESSION['mysqlDatabase'] );
            unset( $_SESSION['mysqlUsername'] );
            unset( $_SESSION['mysqlPassword'] );
        }
    }

    /*
     *  class View
     *  Try to separate view from the rest of the code as best as possible in a single file script.
     */
    class View 
    {
        /*
         * second parameter to be an array of arrays that might include messages and values
         */
        public function render( $view, $parameters=array() )
        {
            $messages = isset( $parameters['messages'] ) ? $parameters['messages'] : false;

            if( $messages != false ) $this->printMessages( $messages );
            $this->$view( $parameters );
        }

        /*
         *  Print table description view
         */
        private function desc( $parameters )
        {
            $results = $parameters['results'];
            $tableName = substr( $_REQUEST['query'], strpos( $_REQUEST['query'], ' ' )+1 );
            ?>
            <p>Description of table <a href="?action=runQuery&amp;query=SELECT%20*%20FROM%20<?php echo $tableName ?>"><?php echo $tableName ?></a></p>
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

        /*
         *  Print select query results
         */
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

        /*
         *  Print show tables view
         */
        private function showTables( $parameters )
        {
            $results = $parameters['results'];
            $tableNameKey = 'Tables_in_'. $_SESSION['mysqlDatabase'];

            ?><table id="results"><?php
            foreach( $results as $result )
            {
                ?>
                    <tr>
                        <td><a href="?action=runQuery&query=DESC%20<?php echo $result[$tableNameKey] ?>">Desc</a></td>
                        <td><a href="?action=runQuery&query=SELECT%20*%20FROM%20<?php echo $result[$tableNameKey] ?>">List Items</a></td>
                        <td><?php echo $result[$tableNameKey] ?></td>
                    </tr>

                <?php
            }
            ?></table><?php
        }

        /*
         *  Print messages view
         */
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

        /*
         *  Print query query textarea
         */
        private function queryBox()
        {
            $query = isset( $_REQUEST['query'] ) ? $_REQUEST['query'] : '';
            ?>

            <div id="queryBox">
                <form action="" method="post">
                    <textarea name="query" id="query"><?php echo $query ?></textarea>
                    <p><input type="submit" name="submit" value="Run Query" /></p>
                    <p><input type="hidden" name="action" value="runQuery" id="runQuery"></p>
                </form>
            </div> <!-- end queryBox -->

            <?php
        }

        /*
         *  Print login form
         */
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

        /*
         *  Print menu
         */
        function menu()
        {
            ?>
                <div id="menu">
                    <ul>
                        <li>Using database <em><?php echo $_SESSION['mysqlDatabase'] ?></em></li> |
                        <li><a href="?action=runQuery&query=show%20tables">Show Tables</a></li> | 
                        <li><a href="?action=logout">Logout</a></li>
                    </ul>
                </div> <!-- end menu -->
            <?php
        }

        /*
         *  Print header
         */
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
                    <?php $this->styles(); ?>
                </head>
                <body>
                    <div id="container">
            <?php 
        }

        /*
         *  Print footer
         */
        function footer()
        {
            ?>

                    </div> <!-- end container -->
                </body>
            </html>

            <?php
        }

        function styles()
        {
            ?>
                <link rel="stylesheet" href="http://twitter.github.com/bootstrap/assets/css/bootstrap-responsive.css" />
                <style>
                    body {
                        font-size: 14px;
                        font-family: Arial;
                    }

                    a:link, a:visited, a:active {
                        text-decoration: none;
                        color: #333;
                    }

                    a:hover { color: #666; }

                    #container { width: 100%; }

                    #menu { width: 400px; }

                    #menu ul {
                       list-style-type: none; 
                       padding-left: 0;
                    }

                    #menu ul li {
                        display: inline;
                    }

                    #queryBox, #query, #runQuery, #menu, #results, #loginForm {
                        clear: both;
                        margin: 0 auto;
                        text-align: center;
                    }

                    #queryBox {
                        width: 650px;
                        padding: 20px;
                        border: 2px solid #aaa;
                        border-radius: 10px;
                    }

                    #container p {
                        text-align: center;
                    }
                    p a { font-style: italic; }

                    #query {
                        padding-top: 3px;
                        width: 600px;
                        height: 75px;
                        border: 1px solid #ccc;
                        border-radius: 10px;
                        text-align: left;
                        text-indent: 10px;
                    }

                    #results {
                        margin-top: 30px;
                        text-align: left;
                        border: 1px solid #ccc;
                        border-radius: 10px;
                        text-align: left;
                        text-indent: 10px;
                    }
                    
                    td {
                        border-bottom: 1px solid #ddd;
                        border-right: 1px solid #ddd;
                    }

                    #results tr:nth-child(odd) { background-color: #fff; }
                    #results tr:nth-child(even) { background-color: #eee; }
                    #results tr:last-child td:first-child { border-bottom-left-radius: 8px }
                    #results tr:last-child td:last-child { border-bottom-right-radius: 8px }

                    tr td:last-child { border-right: none; }
                    tr:last-child  td { border-bottom: none; }

                    #loginForm {
                        width: 250px;
                        padding: 20px;
                        border: 2px solid #a30;
                        border-radius: 10px;
                    }

                    #loginForm dl {
                        margin: 0 auto;
                        width: 182px;
                        color: #333;
                    }
                    
                    #loginForm label {
                        text-align: left;
                        font-family: Arial;
                        font-size: 14px;
                        float: left;
                    }

                    #loginForm dd { 
                        margin-left: 0; 
                        margin-bottom: 10px;
                    }

                    #loginForm input {
                        border: 1px solid #ccc;
                        border-radius: 7px;
                        text-indent: 3px;
                        color: #666;
                    }
                </style>
            <?php
        }
    }
