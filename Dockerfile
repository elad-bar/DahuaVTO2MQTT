FROM python:3.9-alpine
MAINTAINER Elad Bar <elad.bar@hotmail.com>

WORKDIR /app

COPY *.py ./

RUN apk update && \
    apk upgrade && \
    pip install paho-mqtt requests

ENV DAHUA_VTO_HOST=vto-host
ENV DAHUA_VTO_USERNAME=Username
ENV DAHUA_VTO_PASSWORD=Password
ENV MQTT_BROKER_HOST=mqtt-host
ENV MQTT_BROKER_PORT=1883
ENV MQTT_BROKER_USERNAME=Username
ENV MQTT_BROKER_PASSWORD=Password
ENV MQTT_BROKER_TOPIC_PREFIX=DahuaVTO

RUN chmod +x /app/DahuaVTO.py

ENTRYPOINT ["python3", "/app/DahuaVTO.py"]