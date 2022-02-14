_yq() {
  docker run --rm -i -v "${PWD}":/workdir mikefarah/yq "$@"
}

_yq_stdin() {
  docker run --rm -i -v "${PWD}":/workdir mikefarah/yq "$@" -
}
