#!/usr/bin/env python3

import os
import struct
import sys
import logging
import json
import asyncio
import hashlib
from threading import Timer
from time import sleep
from typing import Optional, Callable
import paho.mqtt.client as mqtt
import requests
from requests.auth import HTTPDigestAuth

DEBUG = str(os.environ.get('DEBUG', False)).lower() == str(True).lower()

PROTOCOLS = {
    True: "https",
    False: "http"
}

log_level = logging.DEBUG if DEBUG else logging.INFO

root = logging.getLogger()
root.setLevel(log_level)

stream_handler = logging.StreamHandler(sys.stdout)
stream_handler.setLevel(log_level)
formatter = logging.Formatter('%(asctime)s %(levelname)s %(name)s %(message)s')
stream_handler.setFormatter(formatter)
root.addHandler(stream_handler)

_LOGGER = logging.getLogger(__name__)

DAHUA_DEVICE_TYPE = "deviceType"
DAHUA_SERIAL_NUMBER = "serialNumber"
DAHUA_VERSION = "version"
DAHUA_BUILD_DATE = "buildDate"

DAHUA_GLOBAL_LOGIN = "global.login"
DAHUA_GLOBAL_KEEPALIVE = "global.keepAlive"
DAHUA_EVENT_MANAGER_ATTACH = "eventManager.attach"
DAHUA_CONFIG_MANAGER_GETCONFIG = "configManager.getConfig"
DAHUA_MAGICBOX_GETSOFTWAREVERSION = "magicBox.getSoftwareVersion"
DAHUA_MAGICBOX_GETDEVICETYPE = "magicBox.getDeviceType"

DAHUA_ALLOWED_DETAILS = [
    DAHUA_DEVICE_TYPE, 
    DAHUA_SERIAL_NUMBER
]

ENDPOINT_ACCESS_CONTROL = "accessControl.cgi?action=openDoor&UserID=101&Type=Remote&channel="
ENDPOINT_MAGICBOX_SYSINFO = "magicBox.cgi?action=getSystemInfo"

MQTT_ERROR_DEFAULT_MESSAGE = "Unknown error"

MQTT_ERROR_MESSAGES = {
    1: "MQTT Broker failed to connect: incorrect protocol version",
    2: "MQTT Broker failed to connect: invalid client identifier",
    3: "MQTT Broker failed to connect: server unavailable",
    4: "MQTT Broker failed to connect: bad username or password",
    5: "MQTT Broker failed to connect: not authorised"
}


class DahuaVTOClient(asyncio.Protocol):
    requestId: int
    sessionId: int
    keep_alive_interval: int
    username: str
    password: str
    realm: Optional[str]
    random: Optional[str]
    messages: []
    mqtt_client: mqtt.Client
    dahua_details: {}
    base_url: str
    hold_time: int
    lock_status: {}
    auth: HTTPDigestAuth
    data_handlers: {}

    def __init__(self):
        self.dahua_details = {}
        self.host = os.environ.get('DAHUA_VTO_HOST')
        self.is_ssl = str(os.environ.get('DAHUA_VTO_SSL', False)).lower() == str(True).lower()

        self.base_url = f"{PROTOCOLS[self.is_ssl]}://{self.host}/cgi-bin/"

        self.username = os.environ.get('DAHUA_VTO_USERNAME')
        self.password = os.environ.get('DAHUA_VTO_PASSWORD')
        self.auth = HTTPDigestAuth(self.username, self.password)

        self.mqtt_broker_host = os.environ.get('MQTT_BROKER_HOST')
        self.mqtt_broker_port = os.environ.get('MQTT_BROKER_PORT')
        self.mqtt_broker_username = os.environ.get('MQTT_BROKER_USERNAME')
        self.mqtt_broker_password = os.environ.get('MQTT_BROKER_PASSWORD')

        self.mqtt_broker_topic_prefix = os.environ.get('MQTT_BROKER_TOPIC_PREFIX')
        self.mqtt_open_door_topic = f"{self.mqtt_broker_topic_prefix}/Command/Open"

        self.realm = None
        self.random = None
        self.request_id = 1
        self.sessionId = 0
        self.keep_alive_interval = 0
        self.transport = None
        self.hold_time = 0
        self.lock_status = {}
        self.data_handlers = {}

        self.mqtt_client = mqtt.Client()
        self._loop = asyncio.get_event_loop()

    def initialize_mqtt_client(self):
        _LOGGER.info("Initializing MQTT Broker")
        connected = False
        self.mqtt_client.user_data_set(self)

        self.mqtt_client.username_pw_set(self.mqtt_broker_username, self.mqtt_broker_password)

        self.mqtt_client.on_connect = self.on_mqtt_connect
        self.mqtt_client.on_message = self.on_mqtt_message
        self.mqtt_client.on_disconnect = self.on_mqtt_disconnect

        while not connected:
            try:
                _LOGGER.info("MQTT Broker is trying to connect...")

                self.mqtt_client.connect(self.mqtt_broker_host, int(self.mqtt_broker_port), 60)
                self.mqtt_client.loop_start()

                connected = True

            except Exception as ex:
                exc_type, exc_obj, exc_tb = sys.exc_info()
                error_details = f"error: {ex}, Line: {exc_tb.tb_lineno}"

                _LOGGER.error(f"Failed to connect to broker, retry in 60 seconds, {error_details}")

                sleep(60)

    @staticmethod
    def on_mqtt_connect(client, userdata, flags, rc):
        if rc == 0:
            _LOGGER.info(f"MQTT Broker connected with result code {rc}")

            client.subscribe(userdata.mqtt_open_door_topic)

        else:
            error_message = MQTT_ERROR_MESSAGES.get(rc, MQTT_ERROR_DEFAULT_MESSAGE)

            _LOGGER.error(error_message)

            asyncio.get_event_loop().stop()

    @staticmethod
    def on_mqtt_message(client, userdata, msg):
        payload = None if msg.payload is None else msg.payload.decode("utf-8")

        _LOGGER.debug(f"MQTT Message {msg.topic}: {payload}")

        if msg.topic == userdata.mqtt_open_door_topic:
            data = {}

            if payload is not None and len(payload) > 0:
                data = json.loads(payload)

            door_id = data.get("Door", 1)

            userdata.access_control_open_door(door_id)

    @staticmethod
    def on_mqtt_disconnect(client, userdata, rc):
        connected = False

        while not connected:
            try:
                _LOGGER.info(f"MQTT Broker got disconnected, trying to reconnect...")

                client.connect(userdata.mqtt_broker_host, int(userdata.mqtt_broker_port), 60)
                client.loop_start()

                connected = True

            except Exception as ex:
                exc_type, exc_obj, exc_tb = sys.exc_info()

                _LOGGER.error(f"Failed to reconnect, retry in 60 seconds, error: {ex}, Line: {exc_tb.tb_lineno}")

                sleep(60)

    def connection_made(self, transport):
        _LOGGER.debug("Connection established")

        try:
            self.transport = transport

            self.initialize_mqtt_client()
            self.pre_login()

        except Exception as ex:
            exc_type, exc_obj, exc_tb = sys.exc_info()

            _LOGGER.error(f"Failed to handle message, error: {ex}, Line: {exc_tb.tb_lineno}")

    def data_received(self, data):
        try:
            message = self.parse_response(data)
            _LOGGER.debug(f"Data received: {message}")

            message_id = message.get("id")

            handler: Callable = self.data_handlers.get(message_id, self.handle_default)
            handler(message)

        except Exception as ex:
            exc_type, exc_obj, exc_tb = sys.exc_info()

            _LOGGER.error(f"Failed to handle message, error: {ex}, Line: {exc_tb.tb_lineno}")

    def handle_notify_event_stream(self, params):
        try:
            event_list = params.get("eventList")

            for message in event_list:
                code = message.get("Code")

                for k in self.dahua_details:
                    if k in DAHUA_ALLOWED_DETAILS:
                        message[k] = self.dahua_details.get(k)

                topic = f"{self.mqtt_broker_topic_prefix}/{code}/Event"

                _LOGGER.debug(f"Publishing MQTT message {topic}: {message}")

                self.mqtt_client.publish(topic, json.dumps(message, indent=4))

        except Exception as ex:
            exc_type, exc_obj, exc_tb = sys.exc_info()

            _LOGGER.error(f"Failed to handle event, error: {ex}, Line: {exc_tb.tb_lineno}")

    def handle_default(self, message):
        _LOGGER.info(f"Data received without handler: {message}")

    def eof_received(self):
        _LOGGER.info('Server sent EOF message')

        self._loop.stop()

    def connection_lost(self, exc):
        _LOGGER.error('server closed the connection')

        self._loop.stop()

    def send(self, action, handler, params=None):
        if params is None:
            params = {}

        self.request_id += 1

        message_data = {
            "id": self.request_id,
            "session": self.sessionId,
            "magic": "0x1234",
            "method": action,
            "params": params
        }

        self.data_handlers[self.request_id] = handler

        if not self.transport.is_closing():
            message = self.convert_message(message_data)

            self.transport.write(message)

    @staticmethod
    def convert_message(data):
        message_data = json.dumps(data, indent=4)

        header = struct.pack(">L", 0x20000000)
        header += struct.pack(">L", 0x44484950)
        header += struct.pack(">d", 0)
        header += struct.pack("<L", len(message_data))
        header += struct.pack("<L", 0)
        header += struct.pack("<L", len(message_data))
        header += struct.pack("<L", 0)

        message = header + message_data.encode("utf-8")

        return message

    def pre_login(self):
        _LOGGER.debug("Prepare pre-login message")

        def handle_pre_login(message):
            error = message.get("error")
            params = message.get("params")

            if error is not None:
                error_message = error.get("message")

                if error_message == "Component error: login challenge!":
                    self.random = params.get("random")
                    self.realm = params.get("realm")
                    self.sessionId = message.get("session")

                    self.login()

        request_data = {
            "clientType": "",
            "ipAddr": "(null)",
            "loginType": "Direct",
            "userName": self.username,
            "password": ""
        }

        self.send(DAHUA_GLOBAL_LOGIN, handle_pre_login, request_data)

    def login(self):
        _LOGGER.debug("Prepare login message")

        def handle_login(message):
            params = message.get("params")
            keep_alive_interval = params.get("keepAliveInterval")

            if keep_alive_interval is not None:
                self.keep_alive_interval = keep_alive_interval - 5

                self.load_access_control()
                self.load_version()
                self.load_serial_number()
                self.load_device_type()
                self.attach_event_manager()

                Timer(self.keep_alive_interval, self.keep_alive).start()

        password = self._get_hashed_password(self.random, self.realm, self.username, self.password)

        request_data = {
            "clientType": "",
            "ipAddr": "(null)",
            "loginType": "Direct",
            "userName": self.username,
            "password": password,
            "authorityType": "Default"
        }

        self.send(DAHUA_GLOBAL_LOGIN, handle_login, request_data)

    def attach_event_manager(self):
        _LOGGER.info("Attach event manager")

        def handle_attach_event_manager(message):
            method = message.get("method")
            params = message.get("params")

            if method == "client.notifyEventStream":
                self.handle_notify_event_stream(params)

        request_data = {
            "codes": ['All']
        }

        self.send(DAHUA_EVENT_MANAGER_ATTACH, handle_attach_event_manager, request_data)

    def load_access_control(self):
        _LOGGER.info("Get access control configuration")

        def handle_access_control(message):
            params = message.get("params")
            table = params.get("table")

            for item in table:
                access_control = item.get('AccessProtocol')

                if access_control == 'Local':
                    self.hold_time = item.get('UnlockReloadInterval')

                    _LOGGER.info(f"Hold time: {self.hold_time}")

        request_data = {
            "name": "AccessControl"
        }

        self.send(DAHUA_CONFIG_MANAGER_GETCONFIG, handle_access_control, request_data)

    def load_version(self):
        _LOGGER.info("Get version")

        def handle_version(message):
            params = message.get("params")
            version_details = params.get("version", {})
            build_date = version_details.get("BuildDate")
            version = version_details.get("Version")

            self.dahua_details[DAHUA_VERSION] = version
            self.dahua_details[DAHUA_BUILD_DATE] = build_date

            _LOGGER.info(f"Version: {version}, Build Date: {build_date}")

        self.send(DAHUA_MAGICBOX_GETSOFTWAREVERSION, handle_version)

    def load_device_type(self):
        _LOGGER.info("Get device type")

        def handle_device_type(message):
            params = message.get("params")
            device_type = params.get("type")

            self.dahua_details[DAHUA_DEVICE_TYPE] = device_type

            _LOGGER.info(f"Device Type: {device_type}")

        self.send(DAHUA_MAGICBOX_GETDEVICETYPE, handle_device_type)

    def load_serial_number(self):
        _LOGGER.info("Get serial number")

        def handle_serial_number(message):
            params = message.get("params")
            table = params.get("table", {})
            serial_number = table.get("UUID")

            self.dahua_details[DAHUA_SERIAL_NUMBER] = serial_number

            _LOGGER.info(f"Serial Number: {serial_number}")

        request_data = {
            "name": "T2UServer"
        }

        self.send(DAHUA_CONFIG_MANAGER_GETCONFIG, handle_serial_number, request_data)

    def keep_alive(self):
        _LOGGER.debug("Keep alive")

        def handle_keep_alive(message):
            Timer(self.keep_alive_interval, self.keep_alive).start()

        request_data = {
            "timeout": self.keep_alive_interval,
            "action": True
        }

        self.send(DAHUA_GLOBAL_KEEPALIVE, handle_keep_alive, request_data)

    def access_control_open_door(self, door_id: int = 1):
        is_locked = self.lock_status.get(door_id, False)
        should_unlock = False

        try:
            if is_locked:
                _LOGGER.info(f"Access Control - Door #{door_id} is already unlocked, ignoring request")

            else:
                is_locked = True
                should_unlock = True

                self.lock_status[door_id] = is_locked
                self.publish_lock_state(door_id, False)

                url = f"{self.base_url}{ENDPOINT_ACCESS_CONTROL}{door_id}"

                response = requests.get(url, verify=False, auth=self.auth)

                response.raise_for_status()

        except Exception as ex:
            exc_type, exc_obj, exc_tb = sys.exc_info()

            _LOGGER.error(f"Failed to open door, error: {ex}, Line: {exc_tb.tb_lineno}")

        if should_unlock and is_locked:
            Timer(float(self.hold_time), self.magnetic_unlock, (self, door_id)).start()

    @staticmethod
    def magnetic_unlock(self, door_id):
        self.lock_status[door_id] = False
        self.publish_lock_state(door_id, True)

    def publish_lock_state(self, door_id: int, is_locked: bool):
        state = "Locked" if is_locked else "Unlocked"

        _LOGGER.info(f"Access Control - {state} magnetic lock #{door_id}")

        topic = f"{self.mqtt_broker_topic_prefix}/MagneticLock/Status"
        message = {
            "door": door_id,
            "isLocked": is_locked
        }

        self.mqtt_client.publish(topic, json.dumps(message, indent=4))

    @staticmethod
    def parse_response(response):
        result = None

        try:
            response_parts = str(response).split("\\x00")
            for response_part in response_parts:
                if response_part.startswith("{"):
                    end = response_part.rindex("}") + 1
                    message = response_part[0:end]

                    result = json.loads(message)

        except Exception as e:
            exc_type, exc_obj, exc_tb = sys.exc_info()

            _LOGGER.error(f"Failed to read data: {response}, error: {e}, Line: {exc_tb.tb_lineno}")

        return result

    @staticmethod
    def _get_hashed_password(random, realm, username, password):
        password_str = f"{username}:{realm}:{password}"
        password_bytes = password_str.encode('utf-8')
        password_hash = hashlib.md5(password_bytes).hexdigest().upper()

        random_str = f"{username}:{random}:{password_hash}"
        random_bytes = random_str.encode('utf-8')
        random_hash = hashlib.md5(random_bytes).hexdigest().upper()

        return random_hash


class DahuaVTOManager:
    def __init__(self):
        self._host = os.environ.get('DAHUA_VTO_HOST')

    def initialize(self):
        while True:
            try:
                _LOGGER.info("Connecting")

                loop = asyncio.new_event_loop()

                client = loop.create_connection(DahuaVTOClient, self._host, 5000)
                loop.run_until_complete(client)
                loop.run_forever()
                loop.close()

                _LOGGER.warning("Disconnected, will try to connect in 5 seconds")

                sleep(5)

            except Exception as ex:
                exc_type, exc_obj, exc_tb = sys.exc_info()
                line = exc_tb.tb_lineno

                _LOGGER.error(f"Connection failed will try to connect in 30 seconds, error: {ex}, Line: {line}")

                sleep(30)


manager = DahuaVTOManager()
manager.initialize()
