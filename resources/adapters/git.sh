detect() {
    command -v git >/dev/null 2>&1
}

configure() {
    # Trust bind-mounted repositories regardless of host ownership.
    # dde bind-mounts the main repository's .git into worktree containers, and
    # its files are owned by the host user, which git would otherwise reject with
    # "detected dubious ownership". The container is a single-user, throwaway dev
    # environment, so trusting all paths system-wide is safe here.
    git config --system --add safe.directory '*'
}
