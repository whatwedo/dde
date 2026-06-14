FROM alpine:3.24

RUN apk add --no-cache openssh shadow su-exec

COPY run.sh /run.sh
RUN chmod +x /run.sh

ENV SOCKET_DIR=/tmp/ssh-agent
ENV SSH_AUTH_SOCK=$SOCKET_DIR/socket

VOLUME $SOCKET_DIR
CMD ["/run.sh"]
