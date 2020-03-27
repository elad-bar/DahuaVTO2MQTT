<?PHP
$debug = true;
echo "<** Dahua VTO Cancel Call **>\n";
$Dahua = new Dahua_Functions("192.168.1.208", "admin", "password"); # VTO's IP and user/pwd
$status = $Dahua->Main();
logging("All done");
function logging($text){
    list($ts) = explode(".",microtime(true));
    $dt = new DateTime(date("Y-m-d H:i:s.",$ts));
    $logdate = $dt->format("Y-m-d H:i:s.u");
    echo $logdate.": ";
    print_r($text);
    echo "\n";
}
class Dahua_Functions
{
    private $sock, $host, $port, $credentials;
    private $ID = 0;                        # Our Request / Responce ID that must be in all requests and initated by us
    private $SessionID = 0;                 # Session ID will be returned after successful login
    private $SID = 0;                       # SID will be returned after we called <service>.attach with 'Object ID'
    private $FakeIPaddr = '(null)';         # WebGUI: mask our real IP
    private $clientType = '';               # WebGUI: We do not show up in logs or online users
    function Dahua_Functions($host, $user, $pass)
    {
        $this->host = $host;
        $this->username = $user;
        $this->password = $pass;
    }
    function Gen_md5_hash($Dahua_random, $Dahua_realm, $username, $password)
    {
        $PWDDB_HASH = strtoupper(md5($username.':'.$Dahua_realm.':'.$password));
        $PASS = $username.':'.$Dahua_random.':'.$PWDDB_HASH;
        $RANDOM_HASH = strtoupper(md5($PASS));
        return $RANDOM_HASH;
    }
     
    function disconnect()
    {
        global $id;
        if ($this->sock)
            fclose($this->sock);
    }
         
    function Send($packet)
    {
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
 
        try{
            $msg = $header.$packet;
            $result = fwrite($this->sock, $msg);
        } catch (Exception $e) {
            logging($e);
        }
    }
    function Receive($timeout = 5)
    {
        #
        # We must expect there is no output from remote device
        # Some debug cmd do not return any output, some will return after timeout/failure, most will return directly
        #
        $data = "";
        $P2P_header = "";
        $P2P_data = "";
        $P2P_return_data = [];
        $header_LEN = 0;
 
        try{
            $len = strlen($data);
 
            $read = array($this->sock);
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, $timeout);
            if ($ready > 0) {
                $data .= stream_socket_recvfrom($this->sock, 8192);
            }
        } catch (Exception $e) {
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
                'userName'=>$this->username,
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
 
        $RANDOM_HASH = $this->Gen_md5_hash($RANDOM, $REALM, $this->username, $this->password);
 
        $query_args = array(
            'id'=>10000,
            'magic'=>"0x1234",
            'method'=>"global.login",
            'session'=>$this->SessionID,
            'params'=>array(
                'userName'=>$this->username,
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
            return true;
        }
        logging("Login failed: ".$data['error']['code']." ".$data['error']['message']);
        return false;
    }
    function Main()
    {
        $this->sock = @fsockopen($this->host, 5000, $errno, $errstr, 5);
        if($errno){
            logging("Socket open failed");
            return;
        }
        if (!$this->Login()){
            return;
        }
             
        $query_args = array(                   
                    'id'=>$this->ID,
                    'magic'=>"0x1234",
                    'method'=>"console.runCmd",
                    'params'=>array(
                        'command'=>"hc" //oder "hc"
                        ),           
                    'session'=>$this->SessionID);           
             
        $this->Send(json_encode($query_args));
        $this->Receive();
    }
}
?>
