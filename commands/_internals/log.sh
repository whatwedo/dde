
function _logYellow() {
    printf "\033[1;33m${@}\033[0m\n"
}
function _logRed() {
    printf "\033[0;31m${@}\033[0m\n"
}
function _logGreen() {
    printf "\033[0;32m${@}\033[0m\n"
}
