function _openUrl() {
    for vhost in $(grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2); do
        _logYellow "visit: https://${vhost}"
    done
}
