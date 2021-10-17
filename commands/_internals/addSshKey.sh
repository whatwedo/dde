
function _addSshKey() {
    _logYellow "Adding SSH key (maybe passphrase required)"
    cd ${ROOT_DIR}
    docker-compose exec ssh-agent sh -c /import-keys.sh
}
