#!/usr/bin/php

<?php
define("SHOW_EV",	true);

// define ("CALL_MON_PORT", 9999);

define("DBG_LEV",	1);

require_once("./assets/php/config.php");
require_once("./assets/php/fdl.php");
require_once("./assets/php/classes/mySqliClass.php");


define("CGREEN",	"#23ff23");
define("CGREY",		"#c0c0c0");
define("CYELLOW",	"#ffff00");
define("CRED",		"#ff0000");
define("CBLUE",		"#76c0ff");
define("CORANGE",	"#ff9800");



define("EVT_REQ", "CHANNEL_ANSWER CHANNEL_BRIDGE CHANNEL_CREATE CHANNEL_HANGUP CHANNEL_HOLD CHANNEL_ORIGINATE CHANNEL_OUTGOING  CHANNEL_PROGRESS");

function dbg($lev, $s) {
	if ($lev < DBG_LEV)	return;
	if (is_array($s))		print_r ($s);
	else					echo $s;
	echo "\n";
}

class CallMonSrv {
	private $dtCalls = [];
	private $opCalls = [];
	private $inCalls = [];
	private $odCalls = [];

	private $opExt = null;
	
	private $tOld = "";	
	
	public function mainLoop() {

		if (!$this->constLoad())					return(false);
		if (!$this->opExtSet())						return(false);
		
		// $spyExt = $this->getSpyExt();
		// dbg(1,"Spy Extention : $spyExt");
		
		if (!($fp = $this->event_socket_create())) 	return(false);
		fputs($fp, "events json " . EVT_REQ ."\n\n");
		
		// actual Loop
		$wild = '{"Event-Name":';
		
		$host = 'localhost'; //host
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($socket, 0, CALL_MON_PORT);
		socket_listen($socket);
		$this->clients = array($socket);

		while(true) {
			
			
			$kp = "";
		    if($this->non_block_read(STDIN, $kp)) {
		        $this->keyPressed($kp);
		    }
			
			$this->doHeartbeat();

			$changed = $this->clients;
			socket_select($changed, $null, $null, 0, 10);

			if (in_array($socket, $changed)) {		// NEW CONNECION
				dbg(1,"socket change");
				$socket_new = socket_accept($socket); 
				$this->clients[] = $socket_new; 
				$header = socket_read($socket_new, 1024); 
				
				$this->perform_handshaking($header, $socket_new, $host, CALL_MON_PORT);
				
				socket_getpeername($socket_new, $ip); 		$this->broadcast(["type" => "system", "message"=>$ip." Connected"]);
				echo "$ip connected\n";
				
				echo "sending pars\n";
				$pars = [
					"type"		=>	"pars"
				,	"spyPwd"	=>	SPY_PWD
				,	"spyExt"	=>	$this->getSpyExt()
				];
				
				print_r($pars);
				echo "\n----------------------------------\n";
				
				$msgMasked = $this->mask(json_encode($pars));
				@socket_write($socket_new,$msgMasked,strlen($msgMasked));
				
				
				$found_socket = array_search($socket, $changed);
				unset($changed[$found_socket]);
			}
			
			foreach ($changed as $changed_socket) {	
				
				// Handle received commants
				while(socket_recv($changed_socket, $buf, 1024, 0) >= 1) {
					$txtIn = $this->unmask($buf); 
					//echo "received : $txtIn\n";
					$msgIn = json_decode($txtIn, true); 
					dbg(1, "============= RECEIVED COMMAND FROM callMon ====================");
					print_r($msgIn);
					// dbg(1,$msgIn);
					// handle message somehow .. todo!
					switch($msgIn["msg"]) {
						case "HANGUP" :
							$this->fsCommand("api uuid_kill " . $msgIn["uid"]);
							break;

						case "SPY" : 
							$this->fsCommand("bgapi originate {sip_secure_media=true}user/" . $msgIn["ext"] . " &eavesdrop(" . $msgIn["uid"] . ")");
							// $this->fsCommand("api originate {sip_secure_media=true}sofia/gateway/messagenet/3932188108 &eavesdrop(" . $msgIn["uid"] . ")");
							break;
    					
    					case "OP_FOR_DETT" :
							
							$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
							$rows = $my->myGetRows("SELECT * FROM trunk WHERE ACTIVE = 1 LIMIT 1");
							if ($rows === -1)	return(basicErr($this->my->getLastErr()));
							if ($rows === 0)	return(basicErr("No trunk found!"));
							$trunk = $rows[0]["trunkStr"];
							
							
							$pars = "{opForDet=1,origUid=" . $msgIn["uid"] . ",opExt=" . $msgIn["opExt"]. "}";							
							// bgapi originate {opForDet=1,origUid=cce69c7e-3381-4b8b-9395-820c9b01467b}sofia/gateway/messagenet//0692928424&bridge(/user/1000)
							
							$this->fsCommand("api originate $pars$trunk" . $msgIn["numReq"] . " &bridge($pars" . "user/" . $msgIn["opExt"] . ")"); 
							break;
    					

						default :
							dbg(1,"Unknown command: " . $msgIn["msg"]);
						    break;
					}
					
					break 2; 
				}
				
				$buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
				if ($buf === false) { 
					$found_socket = array_search($changed_socket, $this->clients);
					socket_getpeername($changed_socket, $ip);
					unset($this->clients[$found_socket]);
					$this->broadcast(["type" => "system", "message"=>$ip." disconnected"]);
					echo "$ip disconnected\n";
				}
			}
					
			
			
			if (!feof($fp)) {
				$buffer = fgets($fp) ; // , 256);
				if (strlen($buffer) > 0) {
					if (substr($buffer,0,strlen($wild)) == $wild)  {
						$buffer = substr($buffer,0,strpos($buffer,"}") + 1);
						$data = json_decode($buffer,true);
						if(sizeof($data)>0) {
							$evDett = [ 
								"uid"	=>	getVal($data, "Unique-ID")
							,	"cid"	=>	getVal($data, "Channel-Call-UUID")
							,	"ext"	=>	getVal($data, "Caller-Username")
							,	"stt"	=>	getVal($data, "Channel-State")
							,	"num"	=>	getVal($data, "Caller-Destination-Number")
							,	"evt"	=>	getVal($data, "Event-Name")
							,	"ans"	=>	getVal($data, "Answer-State")
							,	"oid"	=>	getVal($data, "Other-Leg-Unique-ID")
							,	"uia"	=>	getVal($data, "variable_UUIDLegA")
							,	"det"	=>	getVal($data, "variable_dettId")
							,	"int"	=>	getVal($data, "variable_caller")
							,	"opa"	=>	getVal($data, "variable_viaOP")
							,	"ofd"	=>	getVal($data, "variable_opForDet")
							,	"cli"	=>	getVal($data, "variable_callId")
							,	"rid"	=>	getVal($data, "variable_origUid")
							,	"opx"	=>	getVal($data, "variable_opExt")
							];
							$this->eventHandle($evDett);
						}
					}
				}
			}
		}		
	}
	
	protected function doHeartbeat() {
		$tNow = gmdate("d/m/Y H:i:s");
		if($tNow ==$this->tOld ) 	return;
		$this->tOld = $tNow;
		
		$this->broadcast([
			"type"	=>	"heartbeat"
		,	"time"	=>	getTimeInItaly()
		,	"ts"	=>	time()
		]);
	}

	
	protected function eventHandle($ed){

		
		if(SHOW_EV) $this->evtDettDump($ed);		
		// DET DIRECT

		// return;
		
		if ($ed["evt"]=="CHANNEL_HANGUP" && $ed["det"]!="" && $ed["uia"] != "") {
			$this->detDirectHangsup($ed);
			$this->simpleHangup($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_ANSWER" && $ed["det"]!="" && $ed["uia"]!="" && $ed["opa"]=="" && !in_array($ed["uia"], $this->odCalls) && !in_array($ed["uia"], $this->dtCalls))  {
			$this->detDirectAnswered($ed);
			return;
		}
	
		if($ed["evt"]=="CHANNEL_CREATE" && $ed["det"]!="" && $ed["opa"]=="" && $ed["uia"]!="" &&  !in_array($ed["num"], $this->opExt )) {
			$this->detDirectDials($ed); 
			return;
		}

		if($ed["evt"]=="CHANNEL_HANGUP" && $ed["det"]=="" && $ed["num"]==SCRIPT_EXT) {
			$this->detDirectHangupEmpty($ed);
			$this->simpleHangup($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_CREATE" && $ed["det"]=="" &&  $ed["num"]==SCRIPT_EXT ) {
			$this->detDirectLifts($ed); 
			return;
		}

					
		// INCOMING			

		if($ed["evt"]=="CHANNEL_CREATE" && $ed["det"]=="" && $ed["uia"]==""  && $ed["opa"]="" && in_array($ed["num"], $this->opExt)) {
			$this->incommingRing($ed);
			return;
		}
		
		if ($ed["evt"]=="CHANNEL_ANSWER" && $ed["oid"] !="" && array_key_exists($ed["cid"], $this->inCalls))  {
			$this->incommingOpAnswers($ed);
			return;
		}
		
		if ($ed["evt"]=="CHANNEL_HANGUP" && in_array($ed["num"], $this->opExt) && array_key_exists($ed["cid"], $this->inCalls))  {
			$this->incommingOpHangsup($ed);
			$this->simpleHangup($ed);
			return;
		}

		if ($ed["evt"]=="CHANNEL_HOLD" && in_array($ed["num"], $this->opExt)  && array_key_exists($ed["cid"], $this->inCalls)) {
			$this->incommingOpHolds($ed);
			return;
		}

		
		if($ed["evt"]=="CHANNEL_ORIGINATE" &&  in_array($ed["ext"], $this->opExt) && $this->isDetExt($ed["num"])) {
			$this->incommingOpCallsDet($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_ANSWER"	&& in_array($ed["ext"], $this->opExt) && $this->isDetExt($ed["num"]) && $ed["oid"]!="") {
			$this->incommingOpDetAnswers($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_BRIDGE" && array_key_exists($ed["uid"], $this->inCalls)  && array_key_exists($ed["oid"], $this->odCalls)) {
			$this->incommingOpTransfersToDet($ed);
			return;
		}
		// DET THROUGH OP
		
		if($ed["evt"]=="CHANNEL_ORIGINATE" &&  $ed["opa"]!="") {
			$this->dettThruPO($ed);
			return;
		}

		
		if($ed["evt"]=="CHANNEL_ANSWER"  && $ed["opa"]!=""  && $ed["ofd"]=="" && array_key_exists($ed["uia"],$this->odCalls))  {
			$this->dettThruPOAnswered($ed);
			return;
		}

		if($ed["evt"]=="CHANNEL_ORIGINATE" &&  $ed["ofd"]!="" && !in_array($ed["num"], $this->opExt) ) {
			$this->dettThruPOOriginate($ed);
		//		EVT:CHANNEL_ORIGINATE det: uid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 uia: cid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 oid: stt:CS_INIT ext: num:0692928424 int: opa: ofd:1 cli: rid:xxxx
			return;	
		}
		
		if($ed["evt"]=="CHANNEL_ANSWER"  && $ed["ofd"]!="" && !in_array($ed["num"], $this->opExt)) {
			$this->dettThruPOConnected($ed);
			// EVT:CHANNEL_ANSWER det: uid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 uia: cid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 oid: stt:CS_CONSUME_MEDIA ext: num:0692928424 int: opa: ofd:1 cli: rid:xxxx
		}
		
		if($ed["evt"]=="CHANNEL_HANGUP"  && $ed["ofd"]!="" ) {
		// evt:CHANNEL_HANGUP det: uid:a6999c9c-f219-4dd1-a72b-2b9c9d1be86b uia: cid:a6999c9c-f219-4dd1-a72b-2b9c9d1be86b oid:852f9a20-54c3-4296-9a26-c65a4eadba3d stt:CS_EXECUTE ext: num:0692928424 int: opa: ofd:1 cli:
			$this->simpleHangup($ed);
			
		}
			
		
	}	
	
	///////////////////////////////////////////////////////// EVT : DET DIRECT

	protected function detDirectLifts($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Detenuti alza cornetta"
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"uid"		=>	$ed["uid"]
		,	"org"		=>	"Int:" . $ed["ext"]
		,	"orgDescr"	=>	"Interno detenuti sollevato"
		,	"dst"		=>	""
		,	"dstDescr"	=>	""
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"ctStart"	=>	time()
		]);		
	}

	protected function detDirectHangupEmpty($ed) {
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Termina Diretta"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);	
	}	
	
	protected function detDirectDials($ed) {

		$cd = $this->getDetCallDetails($ed["uia"]);
		if($cd===false)
			$cd = [
				$cd["dstDescr"] = "**NUMERO NON TROVATO"
			]; 
		
		$dtCall = [
			"dettUID"	=>	$ed["uia"]
		,	"dettId"	=>	$ed["det"]
		,	"opExt"		=>	""
		,	"detExt"	=>	$ed["int"]
		,	"bnum"		=>	$ed["num"]
		,	"secsGrace"	=>	getVal($cd,"secsGrace",0)
		,	"secsMax"	=>	getVal($cd,"secsMax",0)
		];

		$this->dtCalls[$ed["uia"]] = $dtCall;

		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Chiama"
		,	"uid"		=>	$ed["uia"]
		,	"org"		=>	getVal($cd,"org")
		,	"orgDescr"	=>	getVal($cd,"orgDescr")
		,	"dst"		=>	getVal($cd,"dst")
		,	"dstDescr"	=>	getVal($cd,"dstDescr")
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"record"	=>	1
		,	"secsGrace"	=>	getVal($cd,"secsGrace",0)
		,	"secsMax"	=>	getVal($cd,"secsMax",0)
		]);						
	}
	
	protected function detDirectAnswered($ed) {

		$this->dtCalls[$ed["uia"]]["dieTime"] = time() + intval($this->dtCalls[$ed["uia"]]["secsMax"]) + intval($this->dtCalls[$ed["uia"]]["secsGrace"]);
		$this->dtCalls[$ed["uia"]]["warnTime"] = intval($this->dtCalls[$ed["uia"]]["dieTime"]) - 30;

		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Collegato"
		,	"uid"		=>	$ed["uia"]
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"ctStart"	=>	time() + intval($this->dtCalls[$ed["uia"]]["secsGrace"])
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);								
	}
	
	protected function detDirectHangsup($ed) {
		
		unset($this->dtCalls[$ed["uia"]]);
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Termina Diretta"
		,	"uid"		=>	$ed["uia"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		]);	
	}

	///////////////////////////////////////////////////////// EVT : INCOMING FROM EXT

	protected function incommingRing($ed) {
		$inCall = [
			"callUID"	=>	$ed["cid"]
		,	"bnum"		=>	$ed["ext"]		// CALLER
		,	"opExt"		=>	$ed["num"]
		];	
				
		$this->inCalls[$ed["cid"]] = $inCall;

		$this->sendMsg([
			"evtDescr"	=>	"Chiamata entrante ad operatore"
		,	"uid"		=>	$ed["cid"]
		,	"org"		=>	$ed["ext"]
		,	"orgDescr"	=>	"Chiamata Entrante"
		,	"dst"		=>	$ed["num"]
		,	"dstDescr"	=>	"Posto Operatore"
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	1
		,	"hangup"	=>	0
		,	"ctStart"	=>	time()
		]);
	}
	
	protected function incommingOpAnswers($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Operatore Risponde a chiamata Entrante"
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"ctStart"	=>	time()
		,	"uid"		=>	$ed["cid"]
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);
	}
	
	protected function incommingOpHolds($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"PO mette esterno in attesa"
		,	"uid"		=>	$ed["cid"]
		,	"bgcol"		=>	CBLUE
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"record"	=>	0
		]);				
	}
	
	protected function incommingOpCallsDet($ed) {

		

		$this->odCalls[$ed["uid"]] = [
			"callUID"	=>	$ed["cid"]
		,	"bnum"		=>	$ed["num"]		// CALLER
		,	"opExt"		=>	$ed["ext"]
		,	"dialedNum"	=>	$dialedNum
		,	"descr"		=>	$descr
		,	"callTip"	=>	$callTip
		,	"secsMax"	=>	$secsMax
		,	"record"	=>	$record
		];
		
		$this->sendMsg([
			"evtDescr"	=>	"PO Chiama Interno Detenuti"
		,	"uid"		=>	$ed["uid"]
		,	"org"		=>	"P.O. " . $ed["ext"]
		,	"orgDescr"	=>	"Posto Operatore"
		,	"dst"		=>	$ed["num"]
		,	"dstDescr"	=>	"Interno Detenuti " . $ed["num"]
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	1
		,	"hangup"	=>	0
		,	"record"	=>	0
		]);			
		
	}	
	
	protected function incommingOpHangsup($ed) {
	
		unset($this->inCalls[$ed["uid"]]);

		$this->sendMsg([
			"evtDescr"	=>	"Operatore Termina Entrante"
		,	"uid"		=>	$ed["cid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);
	}

	protected function incommingOpDetAnswers($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto risponde a PO"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"ctStart"	=>	time()
		]);		
	}
	
	protected function incommingOpTransfersToDet($ed) {
		
		$this->sendMsg([
			"evtDescr"	=>	"PO trasferisce a Det"
		,	"uid"		=>	$ed["oid"]
		,	"org"		=>	$this->inCalls[$ed["uid"]]["bnum"]
		,	"orgDescr"	=>	"Chiamata da: " .$this->inCalls[$ed["uid"]]["bnum"]
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"record"	=>	1
		,	"ctStart"	=>	time()
		]);			
		
		$recFile = REC_PATH . $ed["oid"] . ".wav";
		dbg(1,"Recording to: " . $recFile);
		$this->fsCommand("bgapi uuid_record " . $ed["oid"] . " start " . $recFile);
		
	}
	
	protected function simpleHangup($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Hangup Generico"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);		

		$this->sendMsg([
			"evtDescr"	=>	"Hangup Generico"
		,	"uid"		=>	$ed["oid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);		


		if (array_key_exists($ed["uid"], $this->dtCalls))	unset($this->dtCalls[$ed["uid"]]);
		if (array_key_exists($ed["uid"], $this->opCalls))	unset($this->opCalls[$ed["uid"]]);
		if (array_key_exists($ed["uid"], $this->inCalls))	unset($this->inCalls[$ed["uid"]]);
		if (array_key_exists($ed["uid"], $this->odCalls))	unset($this->odCalls[$ed["uid"]]);

		if (array_key_exists($ed["cid"], $this->dtCalls))	unset($this->dtCalls[$ed["cid"]]);
		if (array_key_exists($ed["cid"], $this->opCalls))	unset($this->opCalls[$ed["cid"]]);
		if (array_key_exists($ed["cid"], $this->inCalls))	unset($this->inCalls[$ed["cid"]]);
		if (array_key_exists($ed["cid"], $this->odCalls))	unset($this->odCalls[$ed["cid"]]);

	}
	
	///////////////////////////////////////////////////////// VIA OP 
	
	protected function dettThruPO($ed) {
		$callId = getVal($ed,"cli");
		if ($callId!="") {
			$cd = $this->getDetCallDetails($ed["uia"]);
			
			if ($cd!=[]) {
			
				echo "CHIAMATA VIA OP\n";
				// print_r($cd);
						
				$this->odCalls[$ed["uia"]] =  [
					"callId"		=>	$cd["callId"]
				,	"dettId"		=>	$cd["dettId"]
				,	"orgDescr"		=>	$cd["orgDescr"]
				,	"dstDescr"		=>	$cd["dstDescr"]
				,	"dst"			=>	$cd["dst"]
				,	"secsAvail"		=>	$cd["secsAvail"]
				,	"secsMax"		=>	$cd["secsMax"]
				,	"secsGrace"		=>	$cd["secsGrace"]
				,	"creditInit"	=>	$cd["creditInit"]
				,	"record"		=>	$cd["record"]
				,	"opExt"			=>	$ed["num"]
				];
				
				
				$this->sendMsg([
					"evtDescr"	=>	"Detenuto Chiamata via Op"
				,	"uid"		=>	$ed["uia"]
				,	"org"		=>	getVal($cd,"org")
				,	"orgDescr"	=>	getVal($cd,"orgDescr")
				,	"dst"		=>	getVal($cd,"dst")
				,	"dstDescr"	=>	getVal($cd,"dstDescr")
				,	"bgcol"		=>	CRED
				,	"blink"		=>	1
				,	"hangup"	=>	0
				,	"record"	=>	getVal($cd,"record",0)
				,	"secsGrace"	=>	getVal($cd,"secsGrace",0)
				,	"secsMax"	=>	getVal($cd,"secsMax",0)
				,	"opExt"		=>	$ed["num"]
				]);									
			}
		}
		
	}
	

	protected function dettThruPOAnswered($ed) {
		
		echo "Chiamata via op risposta\n";
		
		// print_r ($this->odCalls[$ed["uia"]]);
		$od = $this->odCalls[$ed["uia"]];
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Da PO risposta"
		,	"uid"		=>	$ed["uia"]
		,	"bgcol"		=>	CRED
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);			
		
	} 
	protected function dettThruPOOriginate($ed) {

		$od = $this->odCalls[$ed["rid"]];
		$this->sendMsg([
			"evtDescr"	=>	"Operatore chiama per Dett"
		,	"uid"		=>	$ed["uid"]
		,	"org"		=>	"Operatore " . $ed["opx"]
		,	"orgDescr"	=>	"Chiamata via PO"
		,	"dst"		=>	$ed["num"]
		,	"dstDescr"	=>	"Richiesto da Detenuto"
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	1
		,	"hangup"	=>	0
		,	"record"	=>	0
		,	"ofd"		=>	$ed["ofd"]
		,	"rid"		=>	$ed["rid"]
		,	"cli"		=>	$ed["cli"]
		]);		
		
	}	


	
	protected function dettThruPOConnected($ed) {
		
		echo "Chiamata da OP per dett risposta da altra parte\n";
		
		// print_r ($this->odCalls[$ed["uia"]]);
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Da PO risposta"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CORANGE
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);			
		
	} 

	
	///////////////////////////////////////////////////////// END EVENTS 
	
	protected function getDetCallDetails($uid) {
		$sql = "SELECT 
					r.callId
				,	r.dettId
				,	r.secsAvail
				,	r.secsMax	
				,	concat('Int:' ,r.ext, ' Tessera:', c.serial) org
				,	CONCAT(d.lname, ' ', d.fname) orgDescr
				,	r.dialedNum dst
				,	CONCAT(CASE r.callTip
						WHEN 'N' THEN 'Ord'
						WHEN 'A'	THEN 'Avv'
						WHEN 'S'	THEN 'Sup'
						WHEN 'X' THEN 'Str'
					END, ' - ' , w.descr) dstDescr
				,	r.creditInit
				,	r.secsGrace
				,	r.secsMax
				,	r.record
				FROM callrec r
				JOIN card c ON c.cardId = r.cardId
				JOIN dett d ON d.dettId = r.dettId
				JOIN wl 	w ON w.wlId = r.wlId
				WHERE r.uuid = '$uid'";

		$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
		$rows = $my->myGetRows($sql);
		if ($rows===-1)	{
			dbg(9,"**Errore SQL" . $my->getLastErr());
			return([]);
		}
		if ($rows===0) {
			dbg(9,"**Chiamata NON trovata $uid");
			return([]);
		}
		
		return($rows[0]);
	}

	
	protected function sendMsg($msg) {
		$msg["type"] = "calldata";
		$this->broadcast($msg);
	}
	
	protected function evtDettDump($ed) {
		dbg(1, "evt:" . $ed["evt"]
		. " det:" . $ed["det"]
		. " uid:" . $ed["uid"]
		. " uia:" . $ed["uia"]
		. " cid:" . $ed["cid"]
		. " oid:" . $ed["oid"]
		. " stt:" . $ed["stt"]
		. " ext:" . $ed["ext"]
		. " num:" . $ed["num"]
		. " int:" .	$ed["int"]
		. " opa:" .	$ed["opa"]
		. " ofd:" .	$ed["ofd"]
		. " cli:" .	$ed["cli"]
		, " rid:" . $ed["rid"]
		, " opx:" . $ed["opx"]
		);
	}	

	protected function opExtSet() {
		$this->opExt = explode(",", OP_EXT);
		if (sizeof($this->opExt)==0)	{
			dbg(9,"No operator extentions defined in constant OP_EXT");
			return(false);
		}
		dbg(1,"Operator Extensions");
		dbg(1,$this->opExt);
		return(true);
		
	}
	
	protected function isDetExt($ext) {
		
		$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
		$rows = $my->myGetRows("SELECT extNum FROM ext WHERE extNum='$ext'");
		if ($rows===-1)	{
			dbg(9,"Error getting det ext:" . $my->getLastErr());
			return(true);
		}
		if ($rows==0)	return(false);
		return(true);
		
	}
	
	
	protected function constLoad() {
    	dbg(1,"Getting constants");
    	$sql = "SELECT constName, constVal FROM const";
    	$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
    	$rows = $my->myGetRows($sql);
    	
    	if ($rows===-1) {
			dbg(9,"**Error getting constants:");
			dbg(9, $my->getLastErr());
			return(false);
    	}

    	if ($rows===0) {
			dbg(9,"**No Constants Found!");
			return(false);
    	}

    	if ($rows!==0 && $rows!==-1) {
			foreach ($rows as $row) {
				define($row["constName"], $row["constVal"]);
				dbg(1,"\t" . $row["constName"] . " = " . $row["constVal"]);
			}
    	}
    	return($row["constVal"]);
	}

	protected function getSpyExt() {
		
		
		$tmpArr = explode(",", SPY_EXT);
		if (sizeof($tmpArr)==0)	{
			dbg(9, "NO spy extensions configured");
			return(false);
		}
		
		$spyExt = [];
		foreach($tmpArr as $ext) 
			$spyExt[$ext] = [
				"used"	=>	0
			];
		
		$ret = $this->fsCommand("api show registrations");
		
		// echo $ret;
		
		if (substr(trim($ret),0,8) != "reg_user")	{
			return(false);
		}

		$lines = explode("\n", $ret);
		for ($i=2; $i<sizeof($lines)-3; $i++) {
			$extRegged = trim(explode(",", $lines[$i])[0]);
			if (array_key_exists($extRegged, $spyExt))
				$spyExt[$extRegged]["used"] = 1;
		}

		$useExt = "";			
		foreach($spyExt as $k => $v) {
			if ($spyExt[$k]["used"]== "0")
				$useExt = $k;
		}
		if ($useExt == "")
			return(false);
		
		return($useExt);
		
		
	}
	
	protected function fsCommand($cmd) {

		dbg(1,"Sending command: " . $cmd);
		
		if ((@$fp = $this->event_socket_create())==false) {
			dbg(9,"Unable to create socket: [" . $this->socketErrNum . "] " . $this->socketErrDescr);
			return(false);
		}

		// $cmd = "api help";
		$response = $this->event_socket_request($fp, $cmd);
		fclose($fp);  
		
		return($response);
		
	}    
	
	protected function keyPressed($k) {
		if ($k == "\n") return;
		$k = strtoupper($k);

		echo "\n===============================================================================\n";
		

		switch($k) {
			case "P" : 
				echo "Prisoner Calls:\n";
				print_r($this->dtCalls);
				break;
		
			case "O" : 
				echo "Operator Calls:\n";
				print_r($this->opCalls);
				break;
				
			case "I" :
				echo "Incoming Calls:\n";
				print_r($this->inCalls);
				break;
				
			case "D" :
				echo "Via Op Calls:\n";
				print_r($this->odCalls);
				break;
			
			default :
				echo "Possible commands:\n";
				echo "P : Prisoner Calls\n";
				echo "O : Operator Calls\n";
				echo "I : Incoming Calls\n";
				echo "D : Via Op Calls\n";
			
		}
		
		echo "\n===============================================================================\n";
		
	}
    
    protected function non_block_read($fd, &$data) {
	    $read = array($fd);
	    $write = array();
	    $except = array();
	    $result = stream_select($read, $write, $except, 0);
	    if($result === false) throw new Exception('stream_select failed');
	    if($result === 0) return false;
	    $data = stream_get_line($fd, 1);
	    return true;
	}
    
    ////////////////////////////// FS SOCKETS
    
	protected function event_socket_create() {
		
		$fp = @fsockopen(FS_HOST, FS_PORT, $errNo, $errDescr);
		if (!$fp) {	
			$this->socketErrNum = $errNo;
			$this->socketErrDescr = $errDescr;
			return(false);
		}

		socket_set_blocking($fp,false);

		if ($fp) {
			while (!feof($fp)) {
				$buffer = fgets($fp, 1024);
				usleep(100); //allow time for reponse
				if (trim($buffer) == "Content-Type: auth/request") {
					fputs($fp, "auth " . FS_PWD . "\n\n");
					break;
				}
			}
			return $fp;
		}	else {
			return false;
		}           
	}
 
	protected function event_socket_request($fp, $cmd) {
		
		$allstuff = "";
	    
	    if ($fp) {    
	        fputs($fp, $cmd."\n\n");    
	        usleep(100); //allow time for reponse
	         
	        $response = "";
	        $i = 0;
	        $contentlength = 0;
	        while (!feof($fp)) {
	           $buffer = fgets($fp, 4096);
	           $allstuff .= $buffer;
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
	        //return($allstuff);
	    }
	    else {
	      echo "no handle";
	    }
	}
	
	////////////////////////////// CALL MON SOCKETS

	protected function broadcast($msg) {
		if ($msg["type"]!="heartbeat")	dbg(1,$msg);
			
		$msgMasked = $this->mask(json_encode($msg));
		foreach($this->clients as $changed_socket) {
			@socket_write($changed_socket,$msgMasked,strlen($msgMasked));
		}
		return true;
	}

	protected function unmask($text) {
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

	protected function mask($text) {
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
/***
	protected function perform_handshaking($receved_header,$client_conn, $host, $port) {
		$headers = array();
		$lines = preg_split("/\r\n/", $receved_header);
		print_r($lines);

		foreach($lines as $line) 		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)) 			{
				$headers[$matches[1]] = $matches[2];
			}
		}
		$secKey = $headers['Sec-WebSocket-Key'];
		// $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));
		$secAccept = base64_encode(SHA1($secKey."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
		//hand shaking header
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
		"Upgrade: websocket\r\n" .
		"Connection: Upgrade\r\n" .
		"WebSocket-Origin: $host\r\n" .
		"WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
		"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($client_conn,$upgrade,strlen($upgrade));
	}	
***/

	protected function perform_handshaking($receved_header,$client_conn, $host, $port) {

		$request = $receved_header;
		print_r($request);
		preg_match('#Sec-WebSocket-Key: (.*)\r\n#', $request, $matches);
		echo "\nMATCHES[1] = " . $matches[1] . "\n";
		$key = base64_encode(pack(
		    'H*',
		    sha1($matches[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
		));
		$headers = "HTTP/1.1 101 Switching Protocols\r\n";
		$headers .= "Upgrade: websocket\r\n";
		$headers .= "Connection: Upgrade\r\n";
		$headers .= "Sec-WebSocket-Version: 13\r\n";
		$headers .= "Sec-WebSocket-Accept: $key\r\n\r\n";
		socket_write($client_conn, $headers, strlen($headers));	
	}	
	///////////////////////////////////////////////////
	
	function __construct() {
    	dbg(3,"Starting Up\n\n");
    	// $this->my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
    }
    
    function __destruct() {
    	// unset($this->my);
    }    			
	
	
}

system('clear');
$cm = new CallMonSrv();
if(!$cm->mainLoop())	exit;


