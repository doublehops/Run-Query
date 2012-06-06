<?php

/*
 *  Author: Damien Buttler
 *  Website: http://www.doublehops.com
 *  Email: damien@doublehops.com
 *
 *  This script is designed to be a stand-alone file to run queries
 *  to your MySQL database. This is most useful when you don't
 *  have ssh access to a host and find phpMyAdmin to much
 *  trouble to install for small tasks. Just upload the 
 *  script to your host and start running queries.
 */

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
        $parameters = array();

        $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : false;

        if( $action == 'logout' )
        {
            $this->session->destroySession();
        }
        elseif( $action == 'import' )
        {
           $result = $this->dbConn->import( $_FILES['importFile'] );
           $parameters['messages'][] = $result;
        }
        elseif( $action == 'export' )
        {
           $result = $this->dbConn->export();
           $parameters['messages'][] = $result;
        }

        $this->view->render( 'header' );

        // If current session available, reconnect to DB
        if( $this->session->isActiveSessionGood() )
        {
            $this->dbConn->connect( $_SESSION['mysqlHost'], $_SESSION['mysqlDatabase'], $_SESSION['mysqlUsername'], $_SESSION['mysqlPassword'] );
        }
        elseif( $action == 'login' )
        {
            $this->session->saveMysqlData( $_POST );

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

        $this->view->render( 'menu' );

        $this->view->render( 'queryBox', $parameters );

        $this->view->render( 'importExport' );

        if( $action == 'runQuery' )
        {
            $object = $this->dbConn->runQuery( $_REQUEST['query'] );

            $fields = isset( $object['fields'] ) ? $object['fields'] : false;

            $this->view->render( $object['queryType'], array( 'results'=>$object['results'], 'fields'=>$fields ) );
        }

        if( isset( $object['numRows'] ) ) $this->view->render( 'rowCount', $object['numRows'] );

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

    public function import( $file )
    {
        $importCommand = $_POST['importCommand'];

        $_SESSION['importCommand'] = $importCommand;

       if( file_exists( $importCommand ) )
       {
            $result = exec( $importCommand .' -u '. $_SESSION['mysqlUsername'] .' -p'. $_SESSION['mysqlPassword'] .' '. $_SESSION['mysqlDatabase'] .' < '. $_FILES['importFile']['tmp_name'] );
            return 'Import file ran';
       }

       return 'Import command not found in system';
    }

    public function export()
    {
        $exportCommand = $_POST['exportCommand'];

        $_SESSION['exportCommand'] = $exportCommand;

       if( file_exists( $exportCommand ) )
       {
            $tmpFile = '/var/tmp/mysqlDump-'. date('Y-m-d_H:i:s') .'.sql';
            $result = exec( $exportCommand .' -u '. $_SESSION['mysqlUsername'] .' -p'. $_SESSION['mysqlPassword'] .' '. $_SESSION['mysqlDatabase'] .' > '. $tmpFile);

            header("Content-Disposition:attachment;filename='". $tmpFile ."'");
            readfile( $tmpFile );
            exit;
       }

       return 'Export command not found in system';
    }

    public function runQuery( $query )
    {
        $queryType = $this->queryType( $query );

        switch ( $queryType )
        {
            case 'insert' :
            case 'update' :
            case 'delete' :

                $numRows = $this->executeMod( $query, PDO::FETCH_ASSOC );
                return array( 'results'=>null, 'queryType'=>'insert', 'fields'=>null, 'numRows'=>$numRows );

                break;

                $numRows = $this->executeMod( $query, PDO::FETCH_ASSOC );
                return array( 'results'=>null, 'queryType'=>'insert', 'fields'=>null, 'numRows'=>$numRows );

                break;

            case 'showTables' :

                $results = $this->executeQuery( 'SHOW TABLES', PDO::FETCH_ASSOC );
                $numRows = count( $results );

                break;

            case 'desc' :

                $results = $this->executeQuery( $query, PDO::FETCH_OBJ );
                $numRows = count( $results );

                break;

            case 'select' :

                $results = $this->executeQuery( $query, PDO::FETCH_ASSOC );
                $numRows = count( $results );

                if( $numRows > 0 ) $fields = $this->getFields( $results );

                if( !$this->isErrorFree() )
                {
                    die( 'handle error' );
                }

                break;
            
            case '' :

                die( 'No query received' );

                break;
        }

        $fields = isset( $fields ) ? $fields : false;

        return array( 'results'=>$results, 'queryType'=>$queryType, 'fields'=>$fields, 'numRows'=>$numRows );
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
    private function getFields( $results )
    {
        $columnTitles = array();

        foreach( $results[0] as $key=>$value )
        {
            $columnTitles[] = $key;
        }

        return $columnTitles;
    }

    /*
     *  Determine the type of query that's being requested and handle accordingly
     */
    private function queryType( $query )
    {
        if( preg_match( '/^INSERT/i', $query ) ) return 'insert';
        if( preg_match( '/^UPDATE/i', $query ) ) return 'update';
        if( preg_match( '/^DELETE/i', $query ) ) return 'delete';
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

    public function saveMysqlData( $post )
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
        
        session_regenerate_id();
        session_destroy();
        setcookie( session_name(), '', time() - 42000) ;
        unset( $_SESSION['mysqlHost'] );
        unset( $_SESSION['mysqlDatabase'] );
        unset( $_SESSION['mysqlUsername'] );
        unset( $_SESSION['mysqlPassword'] );

        header( 'Location: '. $_SERVER['DOCUMENT_URI'] );
        exit;
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
     *
     */
    private function insert( $numRows )
    {
        // Nothing to print here
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
                    <p><input type="submit" name="submit" value="Run Query" class="submit" /></p>
                    <input type="hidden" name="action" value="runQuery" id="runQuery">
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
                    <h1 id="header"><a href="http://www.doublehops.com?runQuery" target="_blank">RunQuery</a></h1>
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
         *  Print the number of rows returned or changed by query
         */
        private function rowCount( $numRows )
        {
            ?><div id="numRows">Rows (selected/modified): <?php echo $numRows ?> </div><?php
        }

        /*
         *  Print menu
         */
        function menu()
        {
            $database = isset( $_SESSION['mysqlDatabase'] ) ? $_SESSION['mysqlDatabase'] : 'none';
            ?>
                <div id="menu">
                    <h1 id="header"><a href="http://www.doublehops.com?runQuery" target="_blank">RunQuery</a></h1>
                    <ul>
                        <li>Using database <em><?php echo $database ?></em></li> |
                        <li><a href="?action=runQuery&query=SHOW%20TABLES">Show tables</a></li> | 
                        <li><a href="#" id="importExportLink">Import/Export</a></li> | 
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
                    <?php $this->javascript(); ?>
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

        function importExport()
        {
            $importCommand = isset( $_SESSION['importCommand'] ) ? $_SESSION['importCommand'] : '/usr/bin/mysql';
            $exportCommand = isset( $_SESSION['exportCommand'] ) ? $_SESSION['exportCommand'] : '/usr/bin/mysqldump';

            ?>
                <div id="importExport" style="display: none;">
                    <div>
                        <p>For *nix only</p>
                        <form action="runQuery.php" method="post" enctype="multipart/form-data">
                            <label for="importCommand">Import command</label>
                            <input type="text" name="importCommand" id="importCommand" value="<?php echo $importCommand ?>" />
                            <input type="file" name="importFile" id="importFile" />
                            <input type="submit" name="submit" value="Import" class="submit" />
                            <input type="hidden" name="action" value="import" />
                        </form>
                        <form action="" method="post" id="exportDiv">
                            <label for="exportCommand">Export command</label>
                            <input type="text" name="exportCommand" id="exportCommand" value="<?php echo $exportCommand ?>" />
                            <input type="submit" name="submit" value="Export" class="submit" />
                            <input type="hidden" name="action" value="export" />
                        </form>
                        </form>
                    </div>
                </div>
            <?php
        }

        function styles()
        {
            ?>
                <link rel="stylesheet" href="http://twitter.github.com/bootstrap/assets/css/bootstrap-responsive.css" />
                <link href='http://fonts.googleapis.com/css?family=Orbitron:900' rel='stylesheet' type='text/css'>
                <style>
                    body, textarea {
                        background-color: #000;
                        font-size: 14px;
                        font-family: Arial, Verdana;
                        color: rgb(21,171,195);
                    }

                    h1#header {
                        margin: 0 auto;
                        width: 250px;
                        text-align: center;
                        font-family: Orbitron, Arial;
                        font-size: 35px;
                        color: #000;
                        -moz-text-shadow: 0 0 0.2em rgb(21,171,195);
                        text-shadow: 0 0 0.2em rgb(21,171,195), 0 0 0.2em rgb(21,171,195);
                    }

                    #loginForm h1#header {
                        margin-bottom: 10px;
                    }

                    h1#header a {
                       color: #000; 
                    }

                    a:link, a:visited, a:active {
                        text-decoration: none;
                        color: rgb(21,171,195);
                    }

                    a:hover { color: rgb(41,200,225); }

                    #container { width: 100%; }

                    #menu { width: 410px; }

                    #menu ul {
                       list-style-type: none; 
                       padding-left: 0;
                    }

                    #menu ul li {
                        display: inline;
                    }

                    #queryBox, #query, #runQuery, #menu, #results, #loginForm, #importExport, #messages {
                        clear: both;
                        margin: 3px auto;
                        text-align: center;
                    }

                    #queryBox, #importExport, #messages {
                        width: 650px;
                        padding: 5px;
                        border: 2px solid rgb(21,171,195);
                        border-radius: 10px;
                    }

                    #exportDiv {
                        margin-top: 20px;
                    }

                    #messages {
                        padding: 2px 20px;
                    }

                    #container p {
                        text-align: center;
                    }
                    p a { font-style: italic; }

                    #query {
                        padding-top: 3px;
                        width: 600px;
                        height: 50px;
                        border: 1px solid rgb(21,171,195);
                        border-radius: 10px;
                        text-align: left;
                        padding: 10px;
                    }

                    #results {
                        margin-top: 30px;
                        text-align: left;
                        border: 1px solid rgb(21,171,195);
                        border-radius: 7px;
                        text-align: left;
                        text-indent: 10px;
                    }
                    
                    #results tr:nth-child(odd) { background-color: #000; }
                    #results tr:nth-child(even) { background-color: #1e1e1e; }
                    #results tr:last-child td:first-child { border-bottom-left-radius: 5px }
                    #results tr:last-child td:last-child { border-bottom-right-radius: 5px }

                    tr td:last-child { border-right: none; }
                    tr:last-child  td { border-bottom: none; }

                    #loginForm {
                        width: 250px;
                        margin-top: 100px;
                        padding: 20px;
                        border: 2px solid rgb(21,171,195);
                        border-radius: 10px;
                    }

                    #loginForm dl {
                        margin: 0 auto;
                        width: 182px;
                        color: rgb(21,171,195);
                    }
                    
                    #loginForm label {
                        text-align: left;
                        font-family: Arial;
                        font-size: 13px;
                        text-indent: 1px;
                        float: left;
                        margin-bottom: 2px;
                    }

                    #loginForm dd { 
                        margin-left: 0; 
                        margin-bottom: 10px;
                    }

                    #loginForm input, #importExport input, .submit, #numRows {
                        color: rgb(0,0,0);
                        border-radius: 7px;
                        border: none;
                        text-align: center;
                        background-color: rgb(21,171,195);
                    }

                    .submit, #numRows {
                        height: 25px;
                        line-height: 25px;
                    }


                    #numRows {
                        margin: 10px auto;
                        width: 250px;
                        border: 1px solid rgb(21,171,195);
                        border-radius: 5px;
                        padding: 5px 3px;
                        text-align: center;
                    }   
                </style>
            <?php
        }

        public function javascript()
        {
            ?>
            <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
                <script>
                    $(document).ready(function(){

                        catchImportExportEvent();
                    });

                    function catchImportExportEvent() {

                        $('#importExportLink').click(function() {

                            $('#importExport').toggle();
                        });
                    }
                </script>

            <?php

        }
    }
