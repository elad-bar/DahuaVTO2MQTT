<?PHP

require('phpMQTT.php');

$debug = true;

logging("<*** Dahua VTO Event Listener START ***>");

$Dahua = new DahuaVTOEventListener();
$Dahua->Main();

function logging($text){
    $date = new DateTime();
    $now = date_format($date, 'Y-m-d H:i:s');

	echo "{$now}\t{$text}\n";
}

class MQTTManager {
    private $host,
            $port,
            $username,
            $password,
            $topicPrefix,
            $client_id,
            $mqtt;

    function MQTTManager() {
        $this->host = getenv("MQTT_BROKER_HOST");
		$this->port = getenv("MQTT_BROKER_PORT");
		$this->username = getenv("MQTT_BROKER_USERNAME");
		$this->password = getenv("MQTT_BROKER_PASSWORD");
		$this->topicPrefix = getenv("MQTT_BROKER_TOPIC_PREFIX");

		$this->client_id = strtolower("dahua-vto-{$this->topicPrefix}");
    }

    function connect() {
        logging("MQTTManager::connect Connecting");

        if(!is_null($this->mqtt)) {
            $this->mqtt->close();
        }

        $this->mqtt = new phpMQTT($this->host, $this->port, $this->client_id);

        $connected = $this->mqtt->connect(true, NULL, $this->username, $this->password);

        return $connected;
    }

    function validateConnection() {
        $now = new DateTime();
	    $diff = $this->lastConnection->getTimestamp() - $now->getTimestamp();

	    if ($diff > 60) {
	        $this->connect();
	    }
	}

	function updateLastConnection() {
	    $now = new DateTime();
        $this->lastConnection = $now;
	}

	function publish($name, $payload){
	    $message = json_encode($payload);
		$topic = "{$this->topicPrefix}/{$name}/Event";

		try {
		    $this->connect();

		    $this->mqtt->publish($topic, $message, 0, false);

            logging("MQTTManager::publish Published topic '{$topic}', message: {$message}");
        }
		catch (Exception $e) {
		    logging("MQTTManager::publish Failed publishing '{$topic}' due to error: {$e}");
        }
	}
}

class DahuaVTOAPI {
    private $serialNumber, $deviceType;

    function DahuaVTOAPI() {
        $this->dahua_host = getenv("DAHUA_VTO_HOST");
        $this->dahua_username = getenv("DAHUA_VTO_USERNAME");
        $this->dahua_password = getenv("DAHUA_VTO_PASSWORD");

        $this->SetDeviceDetails();
    }

    function GetSerialNumber() {
        return $this->serialNumber;
    }

    function GetDeviceType() {
        return $this->deviceType;
    }

    function SetDeviceDetails() {
        try {
            $credentials = "{$this->dahua_username}:{$this->dahua_password}";
            $url = "http://{$this->dahua_host}/cgi-bin/magicBox.cgi?action=getSystemInfo";
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
                    logging("DahuaVTOAPI::SetDeviceDetails Device Type: {$this->deviceType}");
                }
                else if($data_item_key == "serialNumber") {
                    $this->serialNumber = $data_item_value;
                    logging("DahuaVTOAPI::SetDeviceDetails Serial Number: {$this->serialNumber}");
                }
            }
        }
		catch (Exception $e) {
            logging("DahuaVTOAPI::SetDeviceDetails Failed to get device type & SN due to error: {$e}");
        }
	}

	function SaveSnapshot($path="/tmp/") {
	    $credentials = "{$this->dahua_username}:{$this->dahua_password}";
	    $date = date("Y-m-d_H-i-s");
		$filename = "{$path}/DoorBell_{$date}.jpg";
		$fp = fopen($filename, 'wb');
		$url = "http://{$this->dahua_host}/cgi-bin/snapshot.cgi";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, $credentials);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_HTTPGET, 1);
		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
		copy($filename, "{$path}/Doorbell.jpg");
	}

}

class DahuaVTOEventListener { 
    private $sock, $dahua_host, $dahua_port, $dahua_password;
    private $mqtt_manager;
    private $dahua_api;
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

        $this->mqtt_manager = new MQTTManager();
        $this->dahua_api = new DahuaVTOAPI();
    }

	function Gen_md5_hash($Dahua_random, $Dahua_realm) {
		$username = $this->dahua_username;
		$password = $this->dahua_password;

        $base_credentials = "{$username}:{$Dahua_realm}:{$password}";
		$PWDDB_HASH = strtoupper(md5($base_credentials));

		$base_pass = "{$username}:{$Dahua_random}:{$PWDDB_HASH}";
        $RANDOM_HASH = strtoupper(md5($base_pass));

        return $RANDOM_HASH;
    }
	
    function KeepAlive($delay){
		global $debug;
		
        logging("DahuaVTOEventListener::KeepAlive Started keepAlive thread");
        
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
								logging("DahuaVTOEventListener::KeepAlive keepAlive back");
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
                logging("DahuaVTOEventListener::KeepAlive keepAlive failed");
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
            logging("DahuaVTOEventListener::Send Binary header != 32 ({})");
            return;
        }

        $this->ID += 1;

        try {
            $msg = $header.$packet;
            $result = fwrite($this->sock, $msg);
        } 
		catch (Exception $e) {
            logging("DahuaVTOEventListener::Send Failed sending request due to error: {$e}");
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
        logging("DahuaVTOEventListener::Login Started");

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
            logging("DahuaVTOEventListener::Login Completed successfully");
            $this->keepAliveInterval = $data['params']['keepAliveInterval'];
            return true;
        }

		$error_message = json_encode($data);
        logging("DahuaVTOEventListener::Login failed due to error: {$error_message}");
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
                logging("DahuaVTOEventListener::Main Socket open failed");
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
                logging("DahuaVTOEventListener::Main Failure eventManager.attach");
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
            logging("DahuaVTOEventListener::Main Failure no keep alive received");
        }
    }
	
	function EventHandler($data){
		$allEvents = $data['params']['eventList'];
		
		if(count($allEvents) > 1){
			logging("DahuaVTOEventListener::Main Event Manager subscription reply");
		}
		else {		
			foreach ($allEvents as $item) {
				$eventCode = $item['Code'];
				$eventData = $item['Data'];
				$eventAction = $item['Action'];
				
				$payload = array(
					"Action" => $eventAction,
					"Data" => $eventData,
					"deviceType" => $this->dahua_api->GetDeviceType(),
					"serialNumber" => $this->dahua_api->GetSerialNumber()
				);

				$this->mqtt_manager->publish($eventCode, $payload);
			}
		}
		
		return true;
	}
}
?>