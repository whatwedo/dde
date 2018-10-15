#!#!/usr/bin/env bash
set -e

# Vars
DIR=$1
VHOST=$2

# Generate
cd $DIR
if [ ! -f $VHOST.crt ]; then \
    openssl genrsa -out $VHOST.key 2048 && \
    openssl req -new -key $VHOST.key -out $VHOST.csr -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=$VHOST" && \
    echo "authorityKeyIdentifier=keyid,issuer" > $VHOST.ext && \
    echo "basicConstraints=CA:FALSE" >> $VHOST.ext && \
    echo "keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment" >> $VHOST.ext && \
    echo "subjectAltName = @alt_names" >> $VHOST.ext && \
    echo "[alt_names]" >> $VHOST.ext && \
    echo "DNS.1 = $VHOST" >> $VHOST.ext && \
    openssl x509 -req -in $VHOST.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out $VHOST.crt -days 3650 -sha256 -extfile $VHOST.ext; \
fi
