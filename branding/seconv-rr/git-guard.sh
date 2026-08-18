#!/usr/bin/env bash
# Keep the branded copies of GLPI's own logo files out of git's way.
#
# apply.sh overwrites files that GLPI tracks (public/pics/favicon.ico and
# public/pics/logos/logo-*.png), so without this git reports them as modified forever, and
# any upstream change to them makes pull/checkout abort with "local changes would be
# overwritten".
#
#   ./git-guard.sh on                    hide the branded files from git (skip-worktree)
#   ./git-guard.sh off                   let git see them again
#   ./git-guard.sh status                which files are currently hidden
#   ./git-guard.sh run git pull --rebase run a git command with the branding lifted, then
#                                        put it back
#
# `run` is the one that matters: skip-worktree alone does not survive an upstream change to
# these files, because git still refuses to overwrite a dirty worktree. `run` restores the
# pristine files first, so the command sees a clean tree, and re-applies the branding
# afterwards — including when the command fails.

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

# Which tracked files apply.sh is allowed to overwrite.
pathspecs=("public/pics/favicon.ico" "public/pics/logos/logo-*.png")

stash_dir=""

usage() {
    awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
    exit "${1:-1}"
}

branded_files() {
    local -n out=$1
    mapfile -d '' out < <(git ls-files -z -- "${pathspecs[@]}")
    if [ "${#out[@]}" -eq 0 ]; then
        echo "error: no tracked files matched ${pathspecs[*]}" >&2
        exit 1
    fi
}

cmd_on() {
    local files
    branded_files files
    git update-index --skip-worktree -- "${files[@]}"
    echo "hidden from git: ${#files[@]} files"
}

cmd_off() {
    local files
    branded_files files
    git update-index --no-skip-worktree -- "${files[@]}"
    echo "visible to git again: ${#files[@]} files"
}

cmd_status() {
    local hidden
    hidden="$(git ls-files -v -- "${pathspecs[@]}" | grep '^S' || true)"
    if [ -z "$hidden" ]; then
        echo "nothing hidden — the branding is visible to git"
        return
    fi
    while IFS= read -r line; do
        echo "hidden: ${line#S }"
    done <<<"$hidden"
}

# Put the branded files back and re-hide them. Runs on every exit path of `run`, so a failed
# or interrupted command never leaves the worktree with GLPI's stock logos.
restore_branding() {
    [ -n "$stash_dir" ] || return 0
    [ -d "$stash_dir" ] || return 0

    local files file
    branded_files files
    for file in "${files[@]}"; do
        if [ -f "$stash_dir/$file" ]; then
            mkdir -p "$(dirname "$file")"
            cp -p "$stash_dir/$file" "$file"
        fi
    done
    git update-index --skip-worktree -- "${files[@]}"
    rm -rf "$stash_dir"
    stash_dir=""
    echo "branding restored and hidden again"
}

cmd_run() {
    [ "$#" -gt 0 ] || usage

    local files file
    branded_files files

    git update-index --no-skip-worktree -- "${files[@]}"

    stash_dir="$(mktemp -d)"
    trap restore_branding EXIT INT TERM
    for file in "${files[@]}"; do
        [ -f "$file" ] || continue
        mkdir -p "$stash_dir/$(dirname "$file")"
        cp -p "$file" "$stash_dir/$file"
    done

    # Hand git a clean tree, otherwise it refuses to touch these paths at all.
    git checkout -- "${files[@]}"

    "$@"
}

case "${1:-}" in
on) cmd_on ;;
off) cmd_off ;;
status) cmd_status ;;
run)
    shift
    cmd_run "$@"
    ;;
-h | --help) usage 0 ;;
*) usage ;;
esac
