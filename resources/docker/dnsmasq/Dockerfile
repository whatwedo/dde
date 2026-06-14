FROM alpine:3.24

RUN apk add --no-cache dnsmasq

EXPOSE 53/udp 53/tcp

ENTRYPOINT ["dnsmasq", "--no-daemon", "--conf-file=/etc/dnsmasq.conf"]
