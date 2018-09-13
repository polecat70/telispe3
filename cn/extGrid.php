<?php 

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once("../assets/php/config.php");
require_once("../assets/DHTMLX46/codebase/grid_connector.php");
require_once("../assets/DHTMLX46/codebase/db_mysqli.php");


$res = mysqli_connect(T3_SRV, T3_USR, T3_PWD, T3_DB);
if (!$res) {
    echo "ERROR!!";
    exit;        
}

$res->query("SET NAMES 'utf8' COLLATE 'utf8_general_ci'");    

$grid = new GridConnector($res,"MySQLi"); 

$sql = "SELECT 
			e.extId
		,	e.extNum
		,	IF(e.enabled=1,'SI','NO') enabled
		,	s.sectName
		,	e.extDescr
		FROM ext e
		LEFT JOIN sect s ON s.sectId = e.sectId";

 $grid->render_sql($sql, "extId", "extNum,enabled,sectName,extDescr");        
        
?>
        
        