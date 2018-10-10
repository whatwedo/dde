SHELL := /bin/bash
PHONY :=
.DEFAULT_GOAL := help
ROOT_DIR := $(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
CERT_DIR := $(ROOT_DIR)/data/reverseproxy/etc/nginx/certs
MAKEFILE := $(ROOT_DIR)/Makefile
VHOST := $(shell grep VIRTUAL_HOST= docker-compose.yml | head -n1 | cut -d'=' -f2 | xargs)
NETWORK_NAME := dde



.PHONY: help
help: ## Display this message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'



.PHONY: system-up
system-up: ## Initializes and starts dde system infrastructure
	# Create network if required
	docker network create $(NETWORK_NAME) || true

	# Create default docker config.json
	if [ ! -f ~/.docker/config.json ]; then \
		mkdir -p ~/.docker && \
		echo '{}' > ~/.docker/config.json; \
	fi

	# Create CA cert if required
	mkdir -p $(CERT_DIR)
	cd $(CERT_DIR) && \
		if [ ! -f ca.pem ]; then \
			openssl genrsa -out ca.key 2048 && \
			openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=dde" -out ca.pem; \
		fi

	# Create certs used by system services
	$(call generateVhostCert,portainer.test)
	$(call generateVhostCert,mailhog.test)


	# Start containers
	cd $(ROOT_DIR) && docker-compose up -d



.PHONY: system-status
system-status: ## Print dde system status
	cd $(ROOT_DIR) && \
		docker-compose ps



.PHONY: system-start
system-start: ## Start already created system dde environment
	cd $(ROOT_DIR) && \
		docker-compose start



.PHONY: system-stop
system-stop: ## Stop system dde environment
	cd $(ROOT_DIR) && \
		docker-compose stop



.PHONY: system-update
system-update: ## Update dde system

		# Remove dde
		make -f $(MAKEFILE) system-destroy

		# Update dde repository
		cd $(ROOT_DIR) && git pull

		# Start dde
		make -f $(MAKEFILE) system-up



.PHONY: system-destroy
system-destroy: ## Remove system dde infrastructure

	# Remove containers
	cd $(ROOT_DIR) && docker-compose down -v --remove-orphans

	# Remove network if created
	if [ "$$(docker network ls | grep $(NETWORK_NAME))" ]; then \
		docker network rm $(NETWORK_NAME) || true; \
	fi


.PHONY: system-nuke
system-nuke: ## Remove system dde infrastructure and nukes data

	# Remove dde
	make -f $(MAKEFILE) system-destroy

	# Remove data
	cd $(ROOT_DIR) && sudo find ./data/* -maxdepth 1 -not -name .gitkeep -exec rm -rf {} ';'



.PHONY: up
up: ## Creates and starts project containers
	$(call checkProject)

	# Generate SSL cert
	$(call generateVhostCert,$(VHOST))

	# Start containers
	docker-compose up -d

	# Give containers some time to start
	sleep 5

	# Fix UID
	for service in `docker-compose config --services`; do \
		docker-compose exec $$service bash -c ' \
			\
			if [ -f /etc/dde/firstboot ]; then \
				exit; \
			fi && \
			\
			mkdir -p /etc/dde && \
			touch /etc/dde/firstboot && \
			\
			apt-get update && \
			apt-get install -qq wget sed bash-completion && \
			\
			groupadd -g '`id -g`' -o dde && \
			useradd -d /home/dde -u '`id -u`' -g '`id -g`' -c "dde" -s /bin/bash -N -o -m dde && \
			\
			wget -q -O /usr/local/bin/gosu "https://github.com/tianon/gosu/releases/download/1.10/gosu-amd64" && \
			chmod +x /usr/local/bin/gosu && \
			\
			if [ -x `command -v nginx` ]; then \
				usermod -a -G www-data dde && \
				sed -i "s/user www-data;/user dde;/" /etc/nginx/nginx.conf && \
				nginx -s reload; \
			fi && \
			\
			if [ -x `command -v php` ]; then \
				PHP_VERSION=`php -v | cut -d " " -f 2 | cut -c 1-3 | head -n 1` && \
				PHP_PATH=/etc/php/$$PHP_VERSION && \
				\
				if [ -f /usr/sbin/php-fpm$$PHP_VERSION ]; then \
					sed -i "s/user = www-data/user = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/group = www-data/group = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/listen\.owner.*/listen.owner = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/listen\.group.*/listen.group = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/listen\.mode.*/listen.mode = 0666/" $$PHP_PATH/fpm/pool.d/www.conf; \
				fi; \
			fi \
		'; \
	done

.PHONY: status
status: ## Print project status
	$(call checkProject)
	docker-compose ps



.PHONY: start
start: ## Start already created project environment
	$(call checkProject)
	docker-compose start



.PHONY: stop
stop: ## Stop project environment
	$(call checkProject)
	docker-compose stop



.PHONY: update
update: ## Update/rebuild project
	$(call checkProject)

	# Destroy project
	make -f $(MAKEFILE) destroy

	# Pull/build images
	docker-compose build
	docker-compose pull

	# Start project
	make -f $(MAKEFILE) up



.PHONY: destroy
destroy: ## Remove central project infrastructure
	$(call checkProject)

	# Remove containers
	docker-compose down -v --remove-orphans

	# Delete SSL certs
	$(call deleteVhostCert,$(VHOST))



.PHONY: exec
exec: ## Opens shell with user dde on first container
	$(call checkProject)
	docker-compose exec `docker-compose config --services | head -n1` gosu dde bash || true



.PHONY: exec_root
exec_root: ## Opens privileged shell on first container
	$(call checkProject)
	docker-compose exec `docker-compose config --services | head -n1` gosu root bash  || true




.PHONY: log
log: ## Show log output
	docker-compose logs -f --tail=100



#
# FUNCTIONS
#

define checkProject
	if [ ! -f docker-compose.yml ]; then \
		echo docker-compose.yml not found && \
		exit 1; \
	fi
endef


define generateVhostCert
	cd $(CERT_DIR) && \
		if [ ! -f $(1).crt ]; then \
			openssl genrsa -out $(1).key 2048 && \
			openssl req -new -key $(1).key -out $(1).csr -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=$(1)" && \
			echo "authorityKeyIdentifier=keyid,issuer" > $(1).ext && \
			echo "basicConstraints=CA:FALSE" >> $(1).ext && \
			echo "keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment" >> $(1).ext && \
			echo "subjectAltName = @alt_names" >> $(1).ext && \
			echo "[alt_names]" >> $(1).ext && \
			echo "DNS.1 = $(1)" >> $(1).ext && \
			openssl x509 -req -in $(1).csr -CA ca.pem -CAkey ca.key -CAcreateserial -out $(1).crt -days 3650 -sha256 -extfile $(1).ext; \
		fi
endef


define deleteVhostCert
	rm -f $(CERT_DIR)/$(1).*
endef
