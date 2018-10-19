FROM alpine:3.8

ENV SOCKET_DIR /tmp/ssh-agent
ENV SSH_AUTH_SOCK $SOCKET_DIR/socket

# Install system deps OpenSSh
RUN apk add --update openssh shadow && rm -rf /var/cache/apk/*
RUN sed -i 's/^CREATE_MAIL_SPOOL=yes/CREATE_MAIL_SPOOL=no/' /etc/default/useradd

# Install gosu
ENV GOSU_VERSION 1.10
RUN set -ex; \
	\
	apk add --no-cache --virtual .gosu-deps \
		dpkg \
		openssl \
	; \
	\
	dpkgArch="$(dpkg --print-architecture | awk -F- '{ print $NF }')"; \
	wget -O /usr/local/bin/gosu "https://github.com/tianon/gosu/releases/download/$GOSU_VERSION/gosu-$dpkgArch"; \
	wget -O /usr/local/bin/gosu.asc "https://github.com/tianon/gosu/releases/download/$GOSU_VERSION/gosu-$dpkgArch.asc"; \
	\
	chmod +x /usr/local/bin/gosu; \
    # verify that the binary works
	gosu nobody true; \
	\
	apk del .gosu-deps

# Set run configuration
COPY run.sh /run.sh
RUN chmod +x /run.sh
VOLUME $SOCKET_DIR
CMD ["/run.sh"]
