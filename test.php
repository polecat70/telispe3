<?php
	


	

 $req = [
	"action"	=>	"CALL_REQUEST"
,	"uuid"	=>	"b8cfd5a7-1b65-4a77-b6a0-1883963810bb"
,	"ext"	=>	"302"
,	"cardId"	=>	"13"
,	"dettId"	=>	"13"
,	"dialedNum"	=>	"3480623535"
];

doCURLRequest ("http://192.168.1.234/assets/php/ws/wsT3.php", $req, "POST", true);


function doCURLRequest ($url, $api_request_parameters, $method_name = "POST", $debug = false, $headers = false) {

    
    if ($debug) {
        echo "\n=== REQUEST =====================================================\n";
        print_r ($api_request_parameters);
    }
                    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $HTTPQuery =  http_build_query($api_request_parameters);
        
    if ($method_name == "GET") {
        $url .= '?' . $HTTPQuery;
    } else {        
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $HTTPQuery);
    }
    if ($headers!== false)
    	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    else
    	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, TRUE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $api_response = curl_exec($ch);
    $error_no = curl_errno($ch);
    $api_response_info = curl_getinfo($ch);
    if (curl_error($ch)!="") {
        $json = array("success" =>0, "error" => array("message" => curl_error($ch)));
        curl_close($ch);
        return($json);
    }
    if ( false && $debug) {
        echo    $api_response. "\n";        
        print_r ($api_response_info);        
        
    }

    
    curl_close($ch);
    $api_response_header = trim(substr($api_response, 0, $api_response_info['header_size']));
    $api_response_body = substr($api_response, $api_response_info['header_size']);
    $json = json_decode($api_response_body, true);
    if($json==null)
        parse_str($api_response_body, $json);
    
    if ($debug) {
        echo "\n=== RESPONSE =============================================\n";
        print_r($json);
    }
    
    if (!isset($json["success"]))
        $json["success"]=1;
        
    return($json);
}

