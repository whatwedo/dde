
function _addSshKey() {
    _logYellow "Adding SSH key (maybe passphrase required)"

    local sshKeys=$(ls -lA ~/.ssh/* 2> /dev/null | wc -l)
    if [ "$sshKeys" = "0" ]; then
        _logRed "no ssh keys found"
        return
    fi

    local oldPwd=$(pwd)
    cd ${ROOT_DIR}
    docker-compose exec ssh-agent sh -c /import-keys.sh
    cd ${oldPwd}
}
