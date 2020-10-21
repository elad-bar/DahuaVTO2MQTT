# DahuaVTO2MQTT
Listens to events from Dahua VTO unit and publishes them via MQTT Message

[MQTT Events](https://github.com/elad-bar/DahuaVTO2MQTT/blob/master/MQTTEvents.MD)

[Supported Models](https://github.com/elad-bar/DahuaVTO2MQTT/blob/master/SupportedModels.md)

## Environment Variables
```
DAHUA_VTO_HOST: 			Dahua VTO hostname or IP
DAHUA_VTO_USERNAME: 		Dahua VTO username to access (should be admin)
DAHUA_VTO_PASSWORD: 		Dahua VTO administrator password (same as accessing web management)
MQTT_BROKER_HOST: 			MQTT Broker hostname or IP
MQTT_BROKER_PORT: 			MQTT Broker port, default=1883
MQTT_BROKER_USERNAME: 		MQTT Broker username
MQTT_BROKER_PASSWORD: 		MQTT Broker password
MQTT_BROKER_TOPIC_PREFIX: 	MQTT Broker topic prefix, default=DahuaVTO
```

## Run manually
Requirements:
* All environment variables above
* PHP

```
php -f DahuaVTO.php
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
      - MQTT_BROKER_TOPIC_PREFIX=DahuaVTO
```

## Changelog

- 2020-Oct-21 - Reverted connection to per message, docker based image changed to php:7.4.11-cli
- 2020-Oct-12 - Added deviceType and serialNumber to MQTT message's payload, Improved connection to one time instead of per message
- 2020-Sep-04 - Edit Readme file, Added Supported Models and MQTT Events documentation
- 2020-Mar-27 - Added new environment variable - MQTT_BROKER_TOPIC_PREFIX
- 2020-Feb-03 - Initial version combing the event listener with MQTT

## Credits
All credits goes to <a href="https://github.com/riogrande75">@riogrande75</a> who wrote that complicated integration
Original code can be found in <a href="https://github.com/riogrande75/Dahua">@riogrande75/Dahua</a>
