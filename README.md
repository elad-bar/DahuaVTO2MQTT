# DahuaVTO2MQTT

## Description
Listens to events from Dahua VTO unit and publishes them via MQTT Message

## Credits
All credits goes to <a href="https://github.com/riogrande75">@riogrande75</a> who wrote that complicated integration
Original code can be found in <a href="https://github.com/riogrande75/Dahua">@riogrande75/Dahua</a>

## Change-log
2020-Feb-03 - Initial version combing the event listener with MQTT

## Environment Variables
```
DAHUA_VTO_HOST: 		Dahua VTO hostname or IP
DAHUA_VTO_USERNAME: 	Dahua VTO username to access (should be admin)
DAHUA_VTO_PASSWORD: 	Dahua VTO administrator password (same as accessing web management)
MQTT_BROKER_HOST: 		MQTT Broker hostname or IP
MQTT_BROKER_PORT: 		MQTT Broker port, default=1883
MQTT_BROKER_USERNAME: 	MQTT Broker username
MQTT_BROKER_PASSWORD: 	MQTT Broker password
```

## Run manually
Requirements:
* All environment variables above
* PHP

```
php -f DahuaEventHandler.php
```

## Docker Compose
```
version: '2'
services:
  dahuavto2mqtt:
    image: "eladbar/dahuavto2mqtt:latest"
    container_name: "dahuavto2mqtt"
    hostname: "dahuavto2mqtt"
    restart: always
    environment:
      - DAHUA_VTO_HOST=vto-host
      - DAHUA_VTO_USERNAME=Username
      - DAHUA_VTO_PASSWORD=Password
      - MQTT_BROKER_HOST=mqtt-host
      - MQTT_BROKER_PORT=1883
      - MQTT_BROKER_USERNAME=Username
      - MQTT_BROKER_PASSWORD=Password  
```

## MQTT Message (Dahua VTO Event Payload)
Topic will be always DahuaVTO/[EVENT NAME]/Event
Message represent an event

#### Events (With dedicated additional data)
##### CallNoAnswered: Call from VTO
```
{
  "Action": "Start",
  "Data": {
    "CallID": "1",
    "IsEncryptedStream": false,
    "LocaleTime": "2020-03-02 20:11:13",
    "LockNum": 2,
    "SupportPaas": false,
    "TCPPort": 37777,
    "UTC": 1583172673
  }
}
```

##### IgnoreInvite: VTH answered call from VTO


##### VideoMotion: Video motion detected
```
{
  "Action": "Start",
  "Data": {
    "LocaleTime": "2020-03-02 20:44:28",
    "UTC": 1583174668
  }
}
```

##### RtspSessionDisconnect: Rtsp-Session connection connection state changed
Action: Represented whether event Start or Stop
Data.Device: IP of the device connected / disconnected
	
##### BackKeyLight: BackKeyLight with State
```
{
  "Action": "Pulse",
  "Data": {
    "LocaleTime": "2020-03-02 20:24:07",
    "State": 8,
    "UTC": 1583173447
  }
}
```

##### TimeChange: Time changed
```
{
  "Action": "Pulse",
  "Data": {
    "BeforeModifyTime": "02-03-2020 21:41:40",
    "LocaleTime": "2020-03-02 21:41:40",
    "ModifiedTime": "02-03-2020 21:41:39",
    "UTC": 1583178100
  }
}
```

##### NTPAdjustTime: NTP Adjusted time
```
{
  "Action": "Pulse",
  "Data": {
    "Address": "time.windows.com",
    "Before": "02-03-2020 21:41:38",
    "LocaleTime": "2020-03-02 21:41:40",
    "UTC": 1583178100,
    "result": true
  }
}
```

##### KeepLightOn: Keep light state changed
Data.Status: Repesents whether the state changed to On or Off
	
##### VideoBlind: Video got blind state changed
Action: Represents whether event Start or Stop

##### FingerPrintCheck: Finger print check status
Data.FingerPrintID: Finger print ID, if 0, check failed

##### SIPRegisterResult: SIP Device registration status
```
{
  "Action": "Pulse",
  "Data": {
    "Date": "02-03-2020 21:42:59",
    "LocaleTime": "2020-03-02 21:42:59",
    "Success": true,
    "UTC": 1583178179
  }
}
```

##### AccessControl: Someone opened the door
```
{
  "Action": "Pulse",
  "Data": {
    "CardNo": "",
    "CardType": null,
    "LocaleTime": "2020-03-02 20:24:08",
    "Method": 4,			// 4=Remote/WebIf/SIPext | 6=FingerPrint
    "Name": "OpenDoor",		// Access control action name
    "Password": "",
    "ReaderID": "1",
    "RecNo": 691,
    "SnapURL": "",
    "Status": 1,
    "Type": "Entry",
    "UTC": 1583173448,
    "UserID": "" 			// By FingerprintManager / Room Number / SIPext
  }
}
```


##### CallSnap: Call
Data.DeviceType: Which device type
Data.RemoteID: UserID
Data.RemoteIP: IP of VTH / SIP device
Data.ChannelStates: Status

##### Invite: Invite for a call (calling)
```
{
  "Action": "Pulse",
  "Data": {
    "CallID": "1",
    "IsEncryptedStream": false,
    "LocaleTime": "2020-03-02 20:11:13",
    "LockNum": 2,
    "SupportPaas": false,
    "TCPPort": 37777,
    "UTC": 1583172673
  }
}
```
	
##### AccessSnap: ?
Data.FtpUrl: FTP uploaded to

##### RequestCallState: ? 
Action: ?
Data.LocaleTime: Date and time of the event
Data.Index: Index of the call

##### PassiveHungup: Call was dropped
Action 
Data.LocaleTime: Date and time of the event
Data.Index: Index of the call

##### ProfileAlarmTransmit: Alarm triggered
Action: ?
Data.AlarmType: Alarm type
Data.DevSrcType: Device triggered the alarm
Data.SenseMethod: What triggered the alarm

##### BackLightOn: Back light turned-on
```
{
  "Action": "Pulse",
  "Data": {
    "LocaleTime": "2020-03-02 20:24:07",
    "UTC": 1583173447
  }
}
```

##### BackLightOff: Back light turned-on
```
{
  "Action": "Pulse",
  "Data": {
    "LocaleTime": "2020-03-02 20:23:39",
    "UTC": 1583173419
  }
}
```

##### AlarmLocal: Alarm triggered by the VTO unit
```
{
  "Action": "Stop",		//Represents whether event for Start or Stop
  "Data": {
    "LocaleTime": "2020-03-02 20:11:16",
    "UTC": 1583172676
  }
}
```

##### APConnect: AccessPoint got connected (Stop) or disconnected (Start)
```
{
  "Action": "Stop",
  "Data": {
    "Error": "SSIDNotValid",
    "LocaleTime": "2020-03-02 19:20:07",
    "Result": false,
    "Type": "Timerconnect",
    "UTC": 1583158807
  }
}
```

#### Generic structure
```
{
	"id": [MESSAGE ID],
	"method":"client.notifyEventStream",
	"params":{
		"SID":513,
		"eventList":[
			{
				"Action":"[EVENT ACTION]",
				"Code":"[EVENT NAME]",
				"Data":{
					"LocaleTime":"YYYY-MM-DD HH:mm:SS",
					"UTC": [EPOCH TIMESTAMP]
				},
				"Index": [EVENT ID IN MESSAGE],
				"Param":[]
			}
		]
	},
	"session": [SESSION IDENTIFIER]
}
```
