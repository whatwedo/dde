SHELL := /bin/bash
PHONY :=
.DEFAULT_GOAL := help
.EXPORT_ALL_VARIABLES:
ROOT_DIR := $(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
CERT_DIR := $(ROOT_DIR)/data/reverseproxy/etc/nginx/certs
MAKEFILE := $(ROOT_DIR)/Makefile
VHOST := $(shell grep VIRTUAL_HOST= docker-compose.yml | head -n1 | cut -d'=' -f2 | xargs)
NETWORK_NAME := dde
DDE_UID := $(shell id -u)
DDE_GID := $(shell id -g)



.PHONY: help
help: ## Display this message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'



.PHONY: system-up
system-up: ## Initializes and starts dde system infrastructure
	$(call log,"Creating network if required")
	@docker network create $(NETWORK_NAME) || true

	$(call log,"Creating default docker config.json")
	@if [ ! -f ~/.docker/config.json ]; then \
		mkdir -p ~/.docker && \
		echo '{}' > ~/.docker/config.json; \
	fi

	$(call log,"Creating CA cert if required")
	@mkdir -p $(CERT_DIR)
	@cd $(CERT_DIR) && \
		if [ ! -f ca.pem ]; then \
			openssl genrsa -out ca.key 2048 && \
			openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=dde" -out ca.pem; \
		fi

	$(call log,"Creating certs used by system services")
	$(call generateVhostCert,portainer.test)
	$(call generateVhostCert,mailhog.test)

	$(call log,"Starting containers")
	@cd $(ROOT_DIR) && docker-compose up -d

	$(call addSshKey)

	$(call log,"Finished startup successfully")



.PHONY: system-status
system-status: ## Print dde system status
	@cd $(ROOT_DIR) && \
		docker-compose ps



.PHONY: system-start
system-start: ## Start already created system dde environment
	@cd $(ROOT_DIR) && \
		docker-compose start

	$(call addSshKey)



.PHONY: system-stop
system-stop: ## Stop system dde environment
	@cd $(ROOT_DIR) && \
		docker-compose stop



.PHONY: system-update
system-update: ## Update dde system

		$(call log,"Removing dde (system)")
		@make -f $(MAKEFILE) system-destroy

		$(call log,"Updating dde repository")
		@cd $(ROOT_DIR) && git pull

		$(call log,"Updating docker images")
		@docker-compose pull
		@docker-compose build

		$(call log,"Starting dde (system)")
		@make -f $(MAKEFILE) system-up

		$(call log,"Finished update successfully")



.PHONY: system-destroy
system-destroy: ## Remove system dde infrastructure

	$(call log,"Removing containers")
	@cd $(ROOT_DIR) && docker-compose down -v --remove-orphans

	$(call log,"Removing network if created")
	@if [ "$$(docker network ls | grep $(NETWORK_NAME))" ]; then \
		docker network rm $(NETWORK_NAME) || true; \
	fi

	$(call log,"Finished destroying successfully")


.PHONY: system-nuke
system-nuke: ## Remove system dde infrastructure and nukes data

	$(call log,"Removing dde sytem")
	@make -f $(MAKEFILE) system-destroy

	$(call log,"Removing data")
	@cd $(ROOT_DIR) && sudo find ./data/* -maxdepth 1 -not -name .gitkeep -exec rm -rf {} ';'

	$(call log,"Finished nuking successfully")


.PHONY: system-cleanup
system-cleanup: ## Cleanup whole docker environment. USE WITH CAUTION

	$(call log,"Running docker-gc")
	@docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -e REMOVE_VOLUMES=1 spotify/docker-gc sh -c "/docker-gc || true"

	$(call log,"Shrinking down docker data")
	@docker run --rm -it --privileged --pid=host walkerlee/nsenter -t 1 -m -u -i -n fstrim /var/lib/docker

	$(call log,"Finished system cleanup")



.PHONY: up
up: ## Creates and starts project containers
	$(call checkProject)

	$(call log,"Generating SSL cert")
	$(call generateVhostCert,$(VHOST))

	$(call startDockerSync)

	$(call log,"Starting containers")
	@docker-compose up -d

	$(call log,"Giving containers some time to start")
	@sleep 5

	$(call log,"Running container startup tasks")
	@for service in `docker-compose config --services`; do \
		docker-compose exec $$service bash -c ' \
			\
			if [ ! -f /etc/lsb-release ]; then \
				echo Platform not supported for autoconfiguration && \
				exit; \
			fi && \
			\
			if [ -f /etc/dde/firstboot ]; then \
				echo Container already configured && \
				exit; \
			fi && \
			\
			mkdir -p /etc/dde && \
			touch /etc/dde/firstboot && \
			\
			apt-get update && \
			apt-get install -qq wget sed bash-completion && \
			\
			groupadd -g $(DDE_GID) -o dde && \
			useradd -d /home/dde -u $(DDE_UID) -g $(DDE_GID) -c "dde" -s /bin/bash -N -o -m dde && \
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
					sed -i "s/user = www-data/user = dde/" $$PHP_PATH/fpm/pool.d/www.conf && \
					sed -i "s/group = www-data/group = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/listen\.owner.*/listen.owner = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/listen\.group.*/listen.group = dde/" $$PHP_PATH/fpm/pool.d/www.conf  && \
					sed -i "s/listen\.mode.*/listen.mode = 0666/" $$PHP_PATH/fpm/pool.d/www.conf; \
				fi; \
			fi \
		'; \
	done

	$(call log,"Finished startup successfully")


.PHONY: status
status: ## Print project status
	$(call checkProject)
	@docker-compose ps



.PHONY: start
start: ## Start already created project environment
	$(call checkProject)

	$(call startDockerSync)

	$(call log,"Starting docker containers")
	@docker-compose start



.PHONY: stop
stop: ## Stop project environment
	$(call checkProject)

	$(call log,"Starting docker containers")
	@docker-compose stop

	$(call stopDockerSync)



.PHONY: update
update: ## Update/rebuild project
	$(call checkProject)

	$(call log,"Destroying project")
	@make -f $(MAKEFILE) destroy

	$(call log," Pulling/building images")
	@docker-compose build
	@docker-compose pull

	$(call log,"Starting project")
	@make -f $(MAKEFILE) up

	$(call log,"Finished update successfully")



.PHONY: destroy
destroy: ## Remove central project infrastructure
	$(call checkProject)

	$(call log,"Removing containers")
	@docker-compose down -v --remove-orphans

	$(call log,"Deleting SSL certs")
	$(call deleteVhostCert,$(VHOST))

	$(call cleanDockerSync)

	$(call log,"Finished destroying successfully")



.PHONY: exec
exec: ## Opens shell with user dde on first container
	$(call checkProject)
	@docker-compose exec `docker-compose config --services | head -n1` gosu dde bash || true



.PHONY: exec_root
exec_root: ## Opens privileged shell on first container
	$(call checkProject)
	@docker-compose exec `docker-compose config --services | head -n1` gosu root bash  || true




.PHONY: log
log: ## Show log output
	@docker-compose logs -f --tail=100



#
# FUNCTIONS
#

define log
	@tput -T xterm setaf 3
	@shopt -s xpg_echo && echo $1
	@tput -T xterm sgr0
endef



define checkProject
	@if [ "$(ROOT_DIR)" == "$(shell pwd)" ]; then \
		echo dde root is not a valid project directory && \
		exit 1; \
	fi
	@if [ ! -f docker-compose.yml ]; then \
		echo docker-compose.yml not found && \
		exit 1; \
	fi
endef



define generateVhostCert
	@cd $(CERT_DIR) && \
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
	@rm -f $(CERT_DIR)/$(1).*
endef



define startDockerSync
	$(call log,"Starting docker-sync. This can take several minutes depending on your project size")
	@if [ -f docker-sync.yml ]; then \
		docker-sync start; \
	fi
endef



define stopDockerSync
	$(call log,"Stopping docker-sync")
	@if [ -f docker-sync.yml ]; then \
		docker-sync stop; \
	fi
endef



define cleanDockerSync
	$(call log,"Cleaning up docker-sync")
	$(call stopDockerSync)
	@if [ -f docker-sync.yml ]; then \
		docker-sync clean; \
	fi
endef



define addSshKey
	$(call log,"Adding SSH key (maybe passphrase required)")
	@cd $(ROOT_DIR) && docker-compose exec ssh-agent sh -c "ssh-add /home/dde/.ssh/id_rsa && ssh-add -l"
endef
