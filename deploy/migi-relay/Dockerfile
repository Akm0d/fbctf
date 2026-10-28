FROM python:3.14-alpine@sha256:bc62fbcb684904b1bb81a6240041bb60e9c1ee19ddeaf17a6b4297e1cc9c95d2

ARG RELAY_UID=1000
ARG RELAY_GID=1000

RUN addgroup -g "${RELAY_GID}" migi-relay \
    && adduser -D -H -u "${RELAY_UID}" -G migi-relay migi-relay

WORKDIR /opt/migi-relay
COPY relay.py ./relay.py

USER migi-relay
EXPOSE 8090
CMD ["python3", "/opt/migi-relay/relay.py"]
