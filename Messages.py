import struct
import json


def _dumper(obj):
    try:
        return obj.toJSON()
    except:
        return obj.__dict__


def _to_json(obj):
    result = json.dumps(obj, default=_dumper, indent=4)

    return result


class MessageParams:
    clientType: str
    ipAddr: str
    loginType: str
    password: str
    userName: str
    authorityType: str
    codes: []
    timeout: int
    action: bool
    name: str

    def set_name(self, name):
        self.name = name

    def keep_alive(self, timeout):
        self.timeout = timeout
        self.action = True

    def attach(self):
        self.codes = ['All']

    def login(self, username, password=""):
        self.clientType = ""
        self.ipAddr = "(null)"
        self.loginType = "Direct"
        self.password = password
        self.userName = username

        if len(password) > 0:
            self.authorityType = "Default"

    def __repr__(self):
        return f"{self.__dict__}"


class MessageData:
    id: int
    magic: str
    method: str
    params: MessageParams
    session: int

    def __init__(self, message_id, session):
        self.id = message_id
        self.session = session
        self.magic = "0x1234"

    def login(self, username, password=""):
        params = MessageParams()
        params.login(username, password)

        self.method = "global.login"
        self.params = params

    def access_control(self):
        params = MessageParams()
        params.set_name('AccessControl')

        self.method = "configManager.getConfig"
        self.params = params

    def version(self):
        params = MessageParams()
        
        self.method = "magicBox.getSoftwareVersion"
        self.params = params

    def device_type(self):
        params = MessageParams()
        
        self.method = "magicBox.getDeviceType"
        self.params = params


    def serial_number(self):
        params = MessageParams()
        params.set_name('T2UServer')

        self.method = "configManager.getConfig"
        self.params = params

    def attach(self):
        params = MessageParams()
        params.attach()

        self.method = "eventManager.attach"
        self.params = params

    def keep_alive(self, timeout):
        params = MessageParams()
        params.keep_alive(timeout)

        self.method = "global.keepAlive"
        self.params = params

    def to_message(self):
        message_data = json.dumps(self, default=_dumper, indent=4)

        header = struct.pack(">L", 0x20000000)
        header += struct.pack(">L", 0x44484950)
        header += struct.pack(">d", 0)
        header += struct.pack("<L", len(message_data))
        header += struct.pack("<L", 0)
        header += struct.pack("<L", len(message_data))
        header += struct.pack("<L", 0)

        message = header + message_data.encode("utf-8")

        return message

    def __repr__(self):
        return f"{self.__dict__}"
