## install alias for your shell

function system:dde:install:services() {
    system:services:enable dnsmasq
    system:services:enable mailcrab
    system:services:enable mariadb
    system:services:enable reverseproxy
}

