function _ddeCheckUpdate() {
    local oldPwd=$(pwd)
    cd ${ROOT_DIR}
    if [ ! -f "${DATA_DIR}/.dde_update_check" ] || [ $(find "${DATA_DIR}/.dde_update_check" -mtime +1 -print) ]; then
        _logYellow "Check if dde update is available"
        git fetch || true # allow offline usage
        touch ${DATA_DIR}/.dde_update_check
    fi

    local upstream=${1:-'@{u}'}
    local local=$(git rev-parse @)
    local remote=$(git rev-parse "$upstream")
    local base=$(git merge-base @ "$upstream")

    if [ "$local" != "$remote" ] && [ "$local" = "$base" ]; then
        _logRed ""
        _logRed "--------------------------------------------"
        _logRed "|           dde update available           |"
        _logRed "--------------------------------------------"
        _logRed "|       please run dde system:update       |"
        _logRed "--------------------------------------------"
        _logRed ""
    fi

    cd ${oldPwd}
}
