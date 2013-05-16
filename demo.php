<?php
/* WARNING WARNING WARNING WARNING WARNING WARNING WARNING WARNING
 *
 * ON THE PRODUCTION ENVIRONMENT YOU ALWAYS MUST DO ALL POSSIBLE
 * SANITY CHECKS FOR THE INCOMING FORM VARIABLES - HERE WE DO NOT
 * PERFORM CHECKS JUST FOR BRIEF CODE!!!!!!!!!
 *
 * NEVER TRUST TO THE WEB-USERS - ALWAYS USE PARAMETRIZED QUERIES!
 *
 * !!!!!!!!!!!!!! THIS EXAMPLE ALLOWS YOU TO DROP DATABASE !!!!!!!!!!!
 *
 * 0) We assume FBC classes ate in the ../tools/fbdbc-php/ directory - change 
 *    this, if you want.
 * 1) This is quick and dirty example how to use FBC classes, no complains, 
 *    please for html/php spaghetti :) - I know how to use templates.
 * 2) Yes, here is ANSI C++ formatting, just because we use them in our projects,
 *    you are free to reformat the code as you wish.
 * 3) You can change FBConnection to FBRCConnection (do not forget to call
 *    SetRedisCache() method in this case - and all your _READ-ONLY_ queries 
 *    results will be stored in the Redis Databse cache. Sure, you need 
 *    RedisServer too :) see SetRedisCache() method for more details about 
 *    parameters.
 * */

// I`m not real Jedi - so, I allow this code be called only from my workstation
// change IP address of comment entire if check
if ('192.168.2.107' != $_SERVER['REMOTE_ADDR'])
{
	die("Nothing here!");
}


require_once(__DIR__."/../class/tools/fbdbc-php/FBConnection.class.php");
?>
<html>
<head>Firebird SQL Connection classes quick start example</head>
<body><form method='post'>
<table border="1">
<tr><td>Server/port:</td><td><input type='text' name='dbserver' value='localhost'></td><td>User name:</td><td><input type='text' name='dbusername' value='dqdev'></td></tr>
<tr><td>Database:</td><td><input type='text' name='dbname' value='dqh1'></td><td>User password:</td><td><input type='password' name='dbuserpass' value='dqdev'></td></tr>
<tr><td>Charset:</td><td><input type='text' name='dbcharset' value='utf8'></td><td>Transaction</td><td><select name='trtype'><option value='0'>READ-ONLY</option><option value='1'>READ-WRITE</option></select></td></tr>
<tr><td>Buffers</td><td><input type='text' name='dbbuffers' value='1000'></td><td>Query:</td><td><textarea name='fbquery'>select 1 as field1 from rdb$database</textarea></td></tr>
<tr><td align='right' colspan='4'><input type='submit' value='execute'></td></tr></table>
</form>

<?php

if (isset($_REQUEST['fbquery']) && ('' != $_REQUEST['fbquery']))
{
	// executing query
	$dbserver = $_REQUEST['dbserver'];
	$dbname = $_REQUEST['dbname'];
	$dbusername = $_REQUEST['dbusername'];
	$dbuserpass = $_REQUEST['dbuserpass'];
	$dbbuffers = $_REQUEST['dbbufferss'];
	$dbcharset = $_REQUEST['dbcharset'];
	$fbquery = $_REQUEST['fbquery'];
	
	$rw_transaction = false;
	$role=null; // your homework: pass it from form 
	
	if ((int) $_REQUEST['trtype'] > 0)
	{
		$rw_transaction = true;
	}
	try
	{
		$c = new FBConnection(); 
		$c->Connect($dbusername, $dbuserpass, $role, $dbserver.':'.$dbname, $dbcharset, $dbbuffers);
		$r1 = $c->GetAllRows($fbquery, array(''), $rw_transaction);
		print_r($r1);
		$c->Commit(true);
	}
	catch (Exception $ex)
	{
		echo "Get exception:".$ex->getMessage()." in file: ".$ex->getFile()." on line:".$ex->getLine();
	}
}
?>
</body>
</html>
