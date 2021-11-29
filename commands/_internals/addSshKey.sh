
function _addSshKey() {
    _logYellow "Adding SSH key (maybe passphrase required)"

    return
    local cmd="ls -A ~/.ssh 2>/dev/null"
    if [ -n $(${cmd}) ]
    then
        _logGreen "ssh key ok"
    else
        _logRed "add some ssh keys use ssh-keygen"
        exit 1
    fi

    cd ${ROOT_DIR}
    docker-compose exec ssh-agent sh -c /import-keys.sh
}



