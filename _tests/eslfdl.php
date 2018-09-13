#!/usr/bin/php

<?php
require_once("./assets/php/config.php");
require_once("./assets/php/fdl.php");
require_once("./assets/php/classes/mySqliClass.php");


$host = 'localhost'; //host
$port = '9786'; //port
$null = NULL; //null var


//Create TCP/IP sream socket
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
//reuseable port
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

//bind socket to specified host
socket_bind($socket, 0, $port);

//listen to port
socket_listen($socket);

//create & add listning socket to the list
$clients = array($socket);



if ((@$fp = event_socket_create())==false) {
	exit;
}

$stsWatch = ["CS_CONSUME_MEDIA","CS_EXCHANGE_MEDIA", "CS_HANGUP" ];

fputs($fp, "event json channel_state\n\n");

$wild = '{"Event-Name":';
while (true) {
	
	
	$changed = $clients;
	//returns the socket resources in $changed array
	socket_select($changed, $null, $null, 0, 10);
	
	//check for new socket
	if (in_array($socket, $changed)) {
		$socket_new = socket_accept($socket); //accpet new socket
		$clients[] = $socket_new; //add socket to client array
		
		$header = socket_read($socket_new, 1024); //read data sent by the socket
		perform_handshaking($header, $socket_new, $host, $port); //perform websocket handshake
		
		socket_getpeername($socket_new, $ip); //get ip address of connected socket
		$response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' connected'))); //prepare json data
		echo "***** $ip connected \n...";
		send_message($response); //notify all users about new connection
		
		//make room for new socket
		$found_socket = array_search($socket, $changed);
		unset($changed[$found_socket]);
	}	
	
	
	while (!feof($fp)) {
		$buffer = fgets($fp) ; // , 256);
	   	
	   	if (strlen($buffer) > 0) {
			if (substr($buffer,0,strlen($wild)) == $wild)  {
				$buffer = substr($buffer,0,strpos($buffer,"}") + 1);
				$data = json_decode($buffer,true);

				$sts = $data["Channel-State"];
				if (in_array($sts, $stsWatch)) {
					$uid = getVal($data, "Channel-Call-UUID");
    				$ext = getVal($data, "Caller-Username");
        			$num = getVal($data, "Caller-Destination-Number");
					$evt = getVal($data, "Event-Name");
					$ans = getVal($data, "Answer-State");
					echo "$uid\t$ext\t$num\t$ans\n";
					
					$msg = [
						"type"	=>	"callData"
					,	"uid"	=>	$uid
					,	"ext"	=>	$ext
					,	"num"	=>	$num
					,	"ans"	=>	$ans
					];
					
					$response = mask(json_encode($msg)); //prepare json data
					send_message($response); //notify all users about new connection
					
				}				
			}
		}
	}
	   		
}

fclose($fp);  
socket_close($socket);					
					
					
					


function event_socket_create() {
	$fp = @fsockopen(FS_HOST, FS_PORT, $errNo, $errDescr);
	if (!$fp) {	
		echo "ERROR: $errNo - $errDescr";
		return(false);
	}

	socket_set_blocking($fp,false);

	if ($fp) {
		while (!feof($fp)) {
			$buffer = fgets($fp, 1024);
			usleep(100); //allow time for reponse
			if (trim($buffer) == "Content-Type: auth/request") {
				echo "sending AUTH " . FS_PWD . "\n";
				fputs($fp, "AUTH " . FS_PWD . "\n\n");
				break;
			}
		}
		return $fp;
	}	else {
		return false;
	}           
}

function event_socket_request($fp, $cmd) {
	
	if ($fp) {    
	    fputs($fp, $cmd."\n\n");    
	    usleep(100); //allow time for reponse
	     
	    $response = "";
	    $i = 0;
	    $contentlength = 0;
	    while (!feof($fp)) {
	       $buffer = fgets($fp, 4096);
	       if ($contentlength > 0) {
	          $response .= $buffer;
	       }
	        
	       if ($contentlength == 0) { //if contentlenght is already don't process again
	           if (strlen(trim($buffer)) > 0) { //run only if buffer has content
	               $temparray = explode(":", trim($buffer));
	               if ($temparray[0] == "Content-Length") {
	                  $contentlength = trim($temparray[1]);
	               }
	           }
	       }
	        
	       usleep(100); //allow time for reponse
	        
	       //optional because of script timeout //don't let while loop become endless
	       if ($i > 10000) { break; } 
	        
	       if ($contentlength > 0) { //is contentlength set
	           //stop reading if all content has been read.
	           if (strlen($response) >= $contentlength) {  
	              break;
	           }
	       }
	       $i++;
	    }
	     
	    return($response);
	}
	else {
	  echo "no handle";
	}
}
    
    

//Unmask incoming framed message
function unmask($text) {
	$length = ord($text[1]) & 127;
	if($length == 126) {
		$masks = substr($text, 4, 4);
		$data = substr($text, 8);
	}
	elseif($length == 127) {
		$masks = substr($text, 10, 4);
		$data = substr($text, 14);
	}
	else {
		$masks = substr($text, 2, 4);
		$data = substr($text, 6);
	}
	$text = "";
	for ($i = 0; $i < strlen($data); ++$i) {
		$text .= $data[$i] ^ $masks[$i%4];
	}
	return $text;
}

//Encode message for transfer to client.
function mask($text) {
	$b1 = 0x80 | (0x1 & 0x0f);
	$length = strlen($text);
	
	if($length <= 125)	
		$header = pack('CC', $b1, $length);
	elseif($length > 125 && $length < 65536)
		$header = pack('CCn', $b1, 126, $length);
	elseif($length >= 65536)
		$header = pack('CCNN', $b1, 127, $length);
	return $header.$text;
}

//handshake new client.
function perform_handshaking($receved_header,$client_conn, $host, $port)   {
	$headers = array();
	$lines = preg_split("/\r\n/", $receved_header);
	foreach($lines as $line) 	{
		$line = chop($line);
		if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)) 		{
			$headers[$matches[1]] = $matches[2];
		}
	}

	$secKey = $headers['Sec-WebSocket-Key'];
	$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
	//hand shaking header
	$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
	"Upgrade: websocket\r\n" .
	"Connection: Upgrade\r\n" .
	"WebSocket-Origin: $host\r\n" .
	"WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
	"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
	socket_write($client_conn,$upgrade,strlen($upgrade));
}
    

?>