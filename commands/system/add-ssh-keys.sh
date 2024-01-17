## adds ssh key to dde system again
#
# Command
#    system:ssh-key-add
#    system-ssh-key-add

function system:ssh-key-add() {
    cd ${ROOT_DIR}
    _addSshKey
}

function system-ssh-key-add() {
    system:ssh-key-add
}
