<?PHP
require("phpMQTT.php");

$debug = true;

logging("<*** Dahua VTO Event Listener START ***>");

$Dahua = new DahuaVTOEventListener();
$status = $Dahua->Main();

logging("All done");

function logging($text){
	echo $text."\n";
}

class DahuaVTOEventListener { 
    private $sock, $dahua_host, $dahua_port, $dahua_password;
	private $mqtt_server, $mqtt_port, $mqtt_username, $mqtt_password, $mqtt_topicPrefix, $mqtt_client_id;
	private $serialNumber, $deviceType;
    private $ID = 0;                        # Our Request / Responce ID that must be in all requests and initated by us
    private $SessionID = 0;                 # Session ID will be returned after successful login
    private $SID = 0;                       # SID will be returned after we called <service>.attach with 'Object ID'
    private $FakeIPaddr = '(null)';         # WebGUI: mask our real IP
    private $clientType = '';               # WebGUI: We do not show up in logs or online users
    private $keepAliveInterval = 60;
    private $lastKeepAlive = 0;

    function DahuaVTOEventListener() {
        $this->dahua_host = getenv("DAHUA_VTO_HOST");
        $this->dahua_username = getenv("DAHUA_VTO_USERNAME");
        $this->dahua_password = getenv("DAHUA_VTO_PASSWORD");
		
		$this->mqtt_server = getenv("MQTT_BROKER_HOST");
		$this->mqtt_port = getenv("MQTT_BROKER_PORT");
		$this->mqtt_username = getenv("MQTT_BROKER_USERNAME");
		$this->mqtt_password = getenv("MQTT_BROKER_PASSWORD");		
		$this->mqtt_topicPrefix = getenv("MQTT_BROKER_TOPIC_PREFIX");
		
		$this->mqtt_client_id = "dahua-vto-".strtolower($this->mqtt_topicPrefix);	

		$this->init_mqtt();
    }
	
	function init_mqtt() {
		$this->SetDeviceDetails();
		
		$mqtt = new phpMQTT($this->mqtt_server, $this->mqtt_port, $this->mqtt_client_id);
		$mqtt->connect_auto(true, NULL, $this->mqtt_username, $this->mqtt_password);
		
		$this->mqtt = $mqtt;
	}
    
	function Gen_md5_hash($Dahua_random, $Dahua_realm) {
		$username = $this->dahua_username;
		$password = $this->dahua_password;
		
        $PWDDB_HASH = strtoupper(md5($username.':'.$Dahua_realm.':'.$password));
        $PASS = $username.':'.$Dahua_random.':'.$PWDDB_HASH;
        $RANDOM_HASH = strtoupper(md5($PASS));
        return $RANDOM_HASH;
    }
	
	function publish($name, $payload){
		$payload["deviceType"] = $this->deviceType;
		$payload["serialNumber"] = $this->serialNumber;
		
		$mqtt_message = json_encode($payload);
		$topic = $this->mqtt_topicPrefix."/".$name."/Event";
		$log_message = "Topic: ".$topic.", Payload: ".$mqtt_message;
		
		try {
			$mqtt = $this->mqtt;
			$mqtt->publish($topic, $mqtt_message, 0);
				
			logging("MQTT message published, ".$log_message);
        } 
		catch (Exception $e) {
			logging("Failed to publish MQTT message due to error: ".$e.", ".$log_message);
			
			$this->init_mqtt();
        }
	}
	
    function KeepAlive($delay){
		global $debug;
		
        logging("Started keepAlive thread");
        
		while(true){
            $query_args = array(
                'method'=>"global.keepAlive",
                'magic'=>"0x1234",
                'params'=>array(
                    'timeout'=>$delay,
                    'active'=>true
                    ),
                'id'=>$this->ID,
                'session'=>$this->SessionID);
				
            $this->Send(json_encode($query_args));
            $lastKeepAlive = time();
            $keepAliveReceived = false;
			
            while($lastKeepAlive + $delay > time()){
                $data = $this->Receive();
				
                if (!empty($data)){
                    foreach($data as $packet) {
                        $packet = json_decode($packet, true);
						
                        if (array_key_exists('result', $packet)){
                            if($debug) {
								logging("keepAlive back");
							}
							
                            $keepAliveReceived = true;
                        }
                        elseif ($packet['method'] == 'client.notifyEventStream'){
                            $status = $this->EventHandler($packet);
                        }
                    }
                }
            }
            if (!$keepAliveReceived) {
                logging("keepAlive failed");
                return false;
            }
        }
    }
    function Send($packet) {
        if (empty($packet)){
            $packet = '';
        }
		
        $header = pack("N",0x20000000);
        $header .= pack("N",0x44484950);
        $header .= pack("V",$this->SessionID);
        $header .= pack("V",$this->ID);
        $header .= pack("V",strlen($packet));
        $header .= pack("V",0);
        $header .= pack("V",strlen($packet));
        $header .= pack("V",0);

        if (strlen($header) != 32){
            logging("Binary header != 32 ({})");
            return;
        }

        $this->ID += 1;

        try {
            $msg = $header.$packet;
            $result = fwrite($this->sock, $msg);
        } 
		catch (Exception $e) {
            logging($e);
        }
    }
	
    function Receive($timeout = 5) {
        #
        # We must expect there is no output from remote device
        # Some debug cmd do not return any output, some will return after timeout/failure, most will return directly
        #
        $data = "";
        $P2P_header = "";
        $P2P_data = "";
        $P2P_return_data = [];
        $header_LEN = 0;

        try {
            $len = strlen($data);

            $read = array($this->sock);
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, $timeout);
			
            if ($ready > 0) {
                $data .= stream_socket_recvfrom($this->sock, 8192);
            }
			
        } 
		catch (Exception $e) {
            return "";
        }

        if (strlen($data)==0){
            #logging("Nothing received anything from remote");
            return "";
        }

        $LEN_RECVED = 1;
        $LEN_EXPECT = 1;
		
        while (strlen($data)>0){
            if (substr($data,0,8) == pack("N",0x20000000).pack("N",0x44484950)){ # DHIP
                $P2P_header = substr($data,0,32);
                $LEN_RECVED = unpack("V",substr($data,16,4))[1];
                $LEN_EXPECT = unpack("V",substr($data,24,4))[1];
				
                $data = substr($data,32);
            }
            else{
                if($LEN_RECVED > 1){
                    $P2P_data = substr($data,0,$LEN_RECVED);
                    $P2P_return_data[] = $P2P_data;
                }
				
                $data = substr($data,$LEN_RECVED);
				
                if ($LEN_RECVED == $LEN_EXPECT && strlen($data)==0){
                    break;
                }
            }
        }
        return $P2P_return_data;
    }
	
    function Login()
    {
        logging("Start login");

        $query_args = array(
            'id'=>10000,
            'magic'=>"0x1234",
            'method'=>"global.login",
            'params'=>array(
                'clientType'=>$this->clientType,
                'ipAddr'=>$this->FakeIPaddr,
                'loginType'=>"Direct",
                'password'=>"",
                'userName'=>$this->dahua_username,
                ),
            'session'=>0
            );

        $this->Send(json_encode($query_args));
        $data = $this->Receive();
		
        if (empty($data)){
            logging("global.login [random]");
            return false;
        }
		
        $data = json_decode($data[0], true);

        $this->SessionID = $data['session'];
        $RANDOM = $data['params']['random'];
        $REALM = $data['params']['realm'];

        $RANDOM_HASH = $this->Gen_md5_hash($RANDOM, $REALM);

        $query_args = array(
            'id'=>10000,
            'magic'=>"0x1234",
            'method'=>"global.login",
            'session'=>$this->SessionID,
            'params'=>array(
                'userName'=>$this->dahua_username,
                'password'=>$RANDOM_HASH,
                'clientType'=>$this->clientType,
                'ipAddr'=>$this->FakeIPaddr,
                'loginType'=>"Direct",
                'authorityType'=>"Default",
                )
            );
        
		$this->Send(json_encode($query_args));
        $data = $this->Receive();
        
		if (empty($data)){
            return false;
        }
        
		$data = json_decode($data[0], true);
        
		if (array_key_exists('result', $data) && $data['result']){
            logging("Login success");
            $this->keepAliveInterval = $data['params']['keepAliveInterval'];
            return true;
        }
		
        logging("Login failed: ".$data['error']['code']." ".$data['error']['message']);
        return false;
    }
	
    function Main($reconnectTimeout=60) {
        $error = false;
        while (true){
            if($error){
                sleep($reconnectTimeout);
            }
			
            $error = true;
            $this->sock = @fsockopen($this->dahua_host, 5000, $errno, $errstr, 5);
			
            if($errno){
                logging("Socket open failed");
                continue;
            }
			
            if (!$this->Login()){
                continue;
            }
			
            #Listen to all events
            $query_args = array(
                'id'=>$this->ID,
                'magic'=>"0x1234",
                'method'=>"eventManager.attach",
                'params'=>array(
                    'codes'=>["All"]
                    ),
                'session'=>$this->SessionID
                );
            
			$this->Send(json_encode($query_args));
            $data = $this->Receive();
			
            if (!count($data) || !array_key_exists('result', json_decode($data[0], true))){
                logging("Failure eventManager.attach");
                continue;
            }
            else{
                unset($data[0]);
				
                foreach($data as $packet) {
                    $packet = json_decode($packet, true);
                    if ($packet['method'] == 'client.notifyEventStream'){
                        $status = $this->EventHandler($packet);
                    }
                }
            }
            $this->KeepAlive($this->keepAliveInterval);
            logging("Failure no keep alive received");
        }
    }
	
	function EventHandler($data){
		$allEvents = $data['params']['eventList'];
		
		if(count($allEvents) > 1){
			logging("Event Manager subscription reply");
		}
		else {		
			foreach ($allEvents as $item) {
				$eventCode = $item['Code'];
				$eventData = $item['Data'];
				$eventAction = $item['Action'];
				
				$this->SingleEventHandler($eventCode, $eventAction, $eventData);
				
				$payload = array(
					"Action" => $eventAction,
					"Data" => $eventData
				);
				
				$this->publish($eventCode, $payload);
			}
		}
		
		return true;
	}
	
    function SingleEventHandler($eventCode, $eventAction, $eventData) {
		global $debug;
		
		if($eventCode == 'CallNoAnswered'){
			logging("Event Call from VTO");
		}
		elseif($eventCode == 'IgnoreInvite'){
			logging("Event VTH answered call from VTO");
		}
		elseif($eventCode == 'VideoMotion'){
			logging("Event VideoMotion");
			//$this->SaveSnapshot();
		}
		elseif($eventCode == 'RtspSessionDisconnect'){
			if($eventAction == 'Start'){
				logging("Event Rtsp-Session from ".str_replace("::ffff:","",$eventData['Device'])." disconnected");
			}
			elseif($eventAction == 'Stop'){
				logging("Event Rtsp-Session from ".str_replace("::ffff:","",$eventData['Device'])." connected");
			}
		}
		elseif($eventCode == 'BackKeyLight'){
			logging("Event BackKeyLight with State ".$eventData['State']." ");
		}
		elseif($eventCode == 'TimeChange'){
			logging("Event TimeChange, BeforeModifyTime: ".$eventData['BeforeModifyTime'].", ModifiedTime: ".$eventData['ModifiedTime']."");
		}
		elseif($eventCode == 'NTPAdjustTime'){
			if($eventData['result']) {
				logging("Event NTPAdjustTime with ".$eventData['Address']." success");
			}
			else {
				logging("Event NTPAdjustTime failed");
			}
		}
		elseif($eventCode == 'KeepLightOn'){
			if($eventData['Status'] == 'On'){
				logging("Event KeepLightOn");
			}
			elseif($eventData['Status'] == 'Off'){
				logging("Event KeepLightOff");
			}
		}
		elseif($eventCode == 'VideoBlind'){
			if($eventAction == 'Start'){
				logging("Event VideoBlind started");
			}
			elseif($eventAction == 'Stop'){
				logging("Event VideoBlind stopped");
			}
		}
		elseif($eventCode == 'FingerPrintCheck'){
			if($eventData['FingerPrintID'] > -1){
				$finger=($eventData['FingerPrintID']);
				/* 
					$users = array( #From VTO FingerprintManager/FingerprintID
						"0" => "Papa",
						"1" => "Mama",
						"2" => "Kind1",
						"3" => "Kind2");
					$name=$users[$finger];
				*/
				logging("Event FingerPrintCheck success, Finger number ".$eventData['FingerPrintID'].", User ".$name."");
			}
			else {
				logging("Event FingerPrintCheck failed, unknown Finger");
			}
		}
		elseif($eventCode == 'SIPRegisterResult'){
			if($eventAction == 'Pulse'){
				if($eventData['Success']) {
					logging("Event SIPRegisterResult, Success");
				}
				else{
					logging("Event SIPRegisterResult, Failed)");
				}
			}
		}
		elseif($eventCode == 'AccessControl'){
			#Method:4=Remote/WebIf/SIPext,6=FingerPrint; UserID: from VTO FingerprintManager/Room Number or SIPext;
			logging("Event: AccessControl, Name ".$eventData['Name']." Method ".$eventData['Method'].", ReaderID ".$eventData['ReaderID'].", UserID ".$eventData['UserID']);
		}
		elseif($eventCode == 'CallSnap'){
			logging("Event: CallSnap, DeviceType ".$eventData['DeviceType']." RemoteID ".$eventData['RemoteID'].", RemoteIP ".$eventData['RemoteIP']." CallStatus ".$eventData['ChannelStates'][0]);
		}
		elseif($eventCode == 'Invite'){
			logging("Event: Invite,  Action ".$eventList['Action'].", CallID ".$eventData['CallID']." Lock Number ".$eventData['LockNum']);
		}
		elseif($eventCode == 'AccessSnap'){
			logging("Event: AccessSnap,  FTP upload to ".$eventData['FtpUrl']);
		}
		elseif($eventCode == 'RequestCallState'){
			logging("Event: RequestCallState,  Action ".$eventList['Action'].", LocaleTime ".$eventData['LocaleTime']." Index ".$eventData['Index']);
			}
		elseif($eventCode == 'APConnect'){
			logging("Event: AlarmLocal");
		}
		elseif($eventCode == 'BackLightOn'){
			logging("Event: BackLightOn");
		}
		elseif($eventCode == 'BackLightOff'){
			logging("Event: BackLightOff");
		}
		elseif($eventCode == 'AlarmLocal'){
			logging("Event: AlarmLocal");
		}
		elseif($eventCode == 'APConnect'){
			logging("Event: APAccess,  Action ".$eventList['Action'].", LocaleTime ".$eventData['LocaleTime']." Result ".$eventData['Result']." Timerconnect ".$eventData['Timerconnect']." Error ".$eventData['Error']);
		}
		elseif($eventCode == 'ProfileAlarmTransmit'){
			logging("Event: ProfileAlarmTransmit,  Action ".$eventList['Action'].", AlarmType ".$eventData['AlarmType']." DevSrcType ".$eventData['DevSrcType'].", SenseMethod ".$eventData['SenseMethod']);
		}
		elseif($eventCode == 'ProfileAlarmTransmit'){
			logging("Event: ProfileAlarmTransmit,  Action ".$eventList['Action'].", AlarmType ".$eventData['AlarmType']." DevSrcType ".$eventData['DevSrcType'].", SenseMethod ".$eventData['SenseMethod']);
		}
		else{
			logging("Unmapped event (".$eventCode)."), Please report";
		}
		
		return true;
	}
	
	function SetDeviceDetails() {
		$credentials = $this->dahua_username . ":" . $this->dahua_password;
		$url = "http://".$this->dahua_host."/cgi-bin/magicBox.cgi?action=getSystemInfo";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, $credentials);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_HTTPGET, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$data = curl_exec($ch);
		
		curl_close($ch);
		
		$data_items = explode(PHP_EOL, $data);
		
		foreach ($data_items as $data_item) {
			$data_item_parts = explode("=", $data_item);
			$data_item_key = $data_item_parts[0];
			$data_item_value = substr($data_item_parts[1], 0, -1);
			
			if($data_item_key == "deviceType") {
				$this->deviceType = $data_item_value;
				logging("Device Type: ".$this->deviceType);
			}
			else if($data_item_key == "serialNumber") {
				$this->serialNumber = $data_item_value;
				logging("Serial Number: ".$this->serialNumber);
			}
		}
	}
	
	function SaveSnapshot($path="/tmp/") {
		$filename = $path."/DoorBell_".date("Y-m-d_H-i-s").".jpg";
		$fp = fopen($filename, 'wb');
		$url = "http://".$this->dahua_host."/cgi-bin/snapshot.cgi";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, $this->dahua_username . ":" . $this->dahua_password);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_HTTPGET, 1);
		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
		copy($filename, $path."/Doorbell.jpg");
	}
}
?>