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
exec: ## Opens shell on first container
	$(call checkProject)
	docker-compose exec `docker-compose config --services | head -n1` bash



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
