function _ddeCheckUpdate() {
    if [ ! -f "${DATA_DIR}/.dde_update_check" ] || [ $(find "${DATA_DIR}/.dde_update_check" -mtime +1 -print) ]; then
        _logYellow "Check if dde update is available"
        cd ${ROOT_DIR}
        git fetch || true # allow offline usage
        touch ${DATA_DIR}/.dde_update_check
    fi

    dde_git_upstream=${1:-'@{u}'}
    dde_git_local=$(git rev-parse @)
    dde_git_base=$(git merge-base @ "$dde_git_upstream")

    if [ $dde_git_local = $dde_git_base ]; then
        _logRed ""
        _logRed "--------------------------------------------"
        _logRed "|           dde update available           |"
        _logRed "--------------------------------------------"
        _logRed "|       please run dde system:update       |"
        _logRed "--------------------------------------------"
        _logRed ""
    fi
}
