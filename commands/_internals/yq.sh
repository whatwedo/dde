_yq() {
  ${DOCKER_BIN} run --rm -i -v "${PWD}":/workdir mikefarah/yq "$@"
}

_yq_stdin() {
  ${DOCKER_BIN} run --rm -i -v "${PWD}":/workdir mikefarah/yq "$@" -
}
