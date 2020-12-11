# MQTT Message (Dahua VTO Event Payload)
Topic will be always [MQTT_BROKER_TOPIC_PREFIX]/[EVENT NAME]/Event
Message represent an event

## Events (With dedicated additional data)
```json
{
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```


### CallNoAnswered: Call from VTO
```json
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
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### IgnoreInvite: VTH answered call from VTO


### VideoMotion: Video motion detected
```json
{
    "Action": "Start",
    "Data": {
      "LocaleTime": "2020-03-02 20:44:28",
      "UTC": 1583174668
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### RtspSessionDisconnect: Rtsp-Session connection connection state changed
Action: Represented whether event Start or Stop
Data.Device: IP of the device connected / disconnected
	
### BackKeyLight: BackKeyLight with State
```json
{
    "Action": "Pulse",
    "Data": {
      "LocaleTime": "2020-03-02 20:24:07",
      "State": 8,
      "UTC": 1583173447
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### TimeChange: Time changed
```json
{
    "Action": "Pulse",
    "Data": {
      "BeforeModifyTime": "02-03-2020 21:41:40",
      "LocaleTime": "2020-03-02 21:41:40",
      "ModifiedTime": "02-03-2020 21:41:39",
      "UTC": 1583178100
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### NTPAdjustTime: NTP Adjusted time
```json
{
    "Action": "Pulse",
    "Data": {
        "Address": "time.windows.com",
        "Before": "02-03-2020 21:41:38",
        "LocaleTime": "2020-03-02 21:41:40",
        "UTC": 1583178100,
        "result": true
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### KeepLightOn: Keep light state changed
Data.Status: Repesents whether the state changed to On or Off
	
### VideoBlind: Video got blind state changed
Action: Represents whether event Start or Stop

### FingerPrintCheck: Finger print check status
Data.FingerPrintID: Finger print ID, if 0, check failed

### SIPRegisterResult: SIP Device registration status
```json
{
    "Action": "Pulse",
    "Data": {
        "Date": "02-03-2020 21:42:59",
        "LocaleTime": "2020-03-02 21:42:59",
        "Success": true,
        "UTC": 1583178179
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### AccessControl: Someone opened the door
```json
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
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```


### CallSnap: Call
```json
{
    "Action": "Pulse",
    "Data": {
        "DeviceType": "Which device type",
        "RemoteID": "UserID",
        "RemoteIP": "IP of VTH / SIP device",
        "ChannelStates": "Status"
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### Invite: Invite for a call (calling)
```json
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
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```
	
### AccessSnap: ?
```json
{
    "Action": "?",
    "Data": {
        "FtpUrl": "FTP uploaded to",
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### RequestCallState: ? 
```json
{
    "Action": "?",
    "Data": {
        "LocaleTime": "2020-03-02 20:24:07",
        "Index": 1
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### PassiveHungup: Call was dropped
```json
{
    "Action": "?",
    "Data": {
        "LocaleTime": "2020-03-02 20:24:07",
        "Index": 1
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### ProfileAlarmTransmit: Alarm triggered
```json
{
    "Action": "?",
    "Data": {
        "AlarmType": "ALARM TYPE",
        "SenseMethod": "What triggered the alarm",
        "DevSrcType": "Device triggered the alarm"
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### BackLightOn: Back light turned-on
```json
{
    "Action": "Pulse",
    "Data": {
        "LocaleTime": "2020-03-02 20:24:07",
        "UTC": 1583173447
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### BackLightOff: Back light turned-on
```json
{
    "Action": "Pulse",
    "Data": {
      "LocaleTime": "2020-03-02 20:23:39",
      "UTC": 1583173419
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### AlarmLocal: Alarm triggered by the VTO unit
```json
{
    "Action": "Stop",		//Represents whether event for Start or Stop
    "Data": {
      "LocaleTime": "2020-03-02 20:11:16",
      "UTC": 1583172676
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

### APConnect: AccessPoint got connected (Stop) or disconnected (Start)
```json
{
    "Action": "Stop",
    "Data": {
        "Error": "SSIDNotValid",
        "LocaleTime": "2020-03-02 19:20:07",
        "Result": false,
        "Type": "Timerconnect",
        "UTC": 1583158807
    },
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```

## Generic structure
```json
{
    "id": "MESSAGE ID",
    "method":"client.notifyEventStream",
    "params":{
        "SID":513,
        "eventList": [
            {
                "Action":"[EVENT ACTION]",
                "Code":"[EVENT NAME]",
                "Data":{
                    "LocaleTime":"YYYY-MM-DD HH:mm:SS",
                    "UTC": "EPOCH TIMESTAMP"
                },
                "Index": "EVENT ID IN MESSAGE",
                "Param":[]
            }
        ]
    },
    "session": "SESSION IDENTIFIER",
    "deviceType":"Device Model",
    "serialNumber":"Device Serial Number"
}
```
